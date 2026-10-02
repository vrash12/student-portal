<?php

namespace App\Services\Examinations;

use App\Enums\QuestionType;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Support\ScoreBands;
use Illuminate\Support\Collection;

/**
 * Item analysis (difficulty, choice distribution, discrimination) of an
 * examination's submitted attempts.
 *
 * Every figure is derived from each attempt's own delivery and scoring
 * snapshot, never from the current question bank, so later bank edits cannot
 * change historical analysis. Results are aggregate only: no candidate
 * identity, attempt identifier, or individual answer leaves this service.
 */
final class ItemAnalysisService
{
    public const SCOPE_LATEST = 'latest';

    public const SCOPE_ALL = 'all';

    public const SCOPES = [self::SCOPE_LATEST, self::SCOPE_ALL];

    public const SORT_MISSED = 'missed';

    public const SORT_ORDER = 'order';

    public const SORT_DISCRIMINATION = 'discrimination';

    public const SORTS = [self::SORT_MISSED, self::SORT_ORDER, self::SORT_DISCRIMINATION];

    /** Share of ranked attempts in each of the upper and lower groups. */
    public const GROUP_SHARE = 0.27;

    /** Below this many fully scored attempts the discrimination index is not meaningful. */
    public const MIN_ATTEMPTS_FOR_DISCRIMINATION = 10;

    public const MISSED_BELOW_PERCENT = 30.0;

    public const EASY_ABOVE_PERCENT = 90.0;

    private const ATTEMPT_COLUMNS = ['id', 'candidate_id', 'attempt_number', 'delivery', 'answers', 'scoring_key', 'item_scores', 'percentage', 'passed', 'result_status'];

    /**
     * @return array{scope: string, sort: string, summary: array<string, mixed>, discrimination: array{available: bool, rankedAttempts: int, groupSize: int|null, minimum: int, reason: string|null}, questions: list<array<string, mixed>>, undeliveredQuestions: int}
     */
    public function analyse(Examination $examination, string $scope = self::SCOPE_LATEST, string $sort = self::SORT_MISSED): array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_LATEST;
        $sort = in_array($sort, self::SORTS, true) ? $sort : self::SORT_MISSED;

        // Overdue attempts are finalized first so auto-submitted work is included.
        app(CandidateAttemptService::class)->expireDue($examination->id);

        $attempts = $this->submittedAttempts($examination, $scope);
        $positions = ExaminationQuestion::query()->where('examination_id', $examination->id)->pluck('position', 'id');

        $ranked = $attempts->filter(fn (ExaminationAttempt $attempt): bool => $attempt->percentage !== null)
            // Highest percentage first; ties broken by attempt id so groups are deterministic.
            ->sort(fn (ExaminationAttempt $a, ExaminationAttempt $b): int => [(float) $b->percentage, $a->id] <=> [(float) $a->percentage, $b->id])
            ->values();
        $groups = $this->groups($ranked);

        $items = $this->collectItems($attempts, $examination, $groups);
        $questions = collect($items)
            ->map(fn (array $item): array => $this->present($item, $positions, $groups !== null))
            ->values();
        $questions = $this->sortQuestions($questions, $sort);

        return [
            'scope' => $scope,
            'sort' => $sort,
            'summary' => $this->summary($examination, $attempts, $ranked),
            'discrimination' => [
                'available' => $groups !== null,
                'rankedAttempts' => $ranked->count(),
                'groupSize' => $groups === null ? null : count($groups['upper']),
                'minimum' => self::MIN_ATTEMPTS_FOR_DISCRIMINATION,
                'reason' => $groups === null ? $this->discriminationReason($attempts->count(), $ranked->count()) : null,
            ],
            'questions' => $questions->all(),
            'undeliveredQuestions' => $positions->keys()->diff(array_keys($items))->count(),
        ];
    }

    /**
     * One query for the whole examination, selecting only the snapshot and
     * result columns the analysis needs.
     *
     * @return Collection<int, ExaminationAttempt>
     */
    private function submittedAttempts(Examination $examination, string $scope): Collection
    {
        return ExaminationAttempt::query()
            ->select(self::ATTEMPT_COLUMNS)
            ->where('examination_id', $examination->id)
            ->where('status', 'submitted')
            ->when($scope === self::SCOPE_LATEST, fn ($query) => $query->whereIn('id', ExaminationAttempt::query()
                ->selectRaw('MAX(id)')
                ->where('examination_id', $examination->id)
                ->where('status', 'submitted')
                ->groupBy('candidate_id')))
            ->orderBy('id')
            ->get();
    }

    /**
     * Upper and lower 27% of fully scored attempts by total percentage, as
     * sets of attempt ids, or null when there are too few attempts.
     *
     * @param  Collection<int, ExaminationAttempt>  $ranked  highest percentage first
     * @return array{upper: array<int, true>, lower: array<int, true>}|null
     */
    private function groups(Collection $ranked): ?array
    {
        if ($ranked->count() < self::MIN_ATTEMPTS_FOR_DISCRIMINATION) {
            return null;
        }
        $size = max(1, (int) round($ranked->count() * self::GROUP_SHARE));

        return [
            'upper' => $ranked->take($size)->mapWithKeys(fn (ExaminationAttempt $attempt): array => [$attempt->id => true])->all(),
            'lower' => $ranked->reverse()->take($size)->mapWithKeys(fn (ExaminationAttempt $attempt): array => [$attempt->id => true])->all(),
        ];
    }

    /**
     * Tallies every delivered item across the attempts, keyed by examination question id.
     *
     * @param  Collection<int, ExaminationAttempt>  $attempts
     * @param  array{upper: array<int, true>, lower: array<int, true>}|null  $groups
     * @return array<int, array<string, mixed>>
     */
    private function collectItems(Collection $attempts, Examination $examination, ?array $groups): array
    {
        $items = [];
        foreach ($attempts as $attempt) {
            $key = $attempt->scoring_key ?? [];
            $answers = $attempt->answers ?? [];
            $scores = $attempt->item_scores ?? [];
            $group = match (true) {
                $groups !== null && isset($groups['upper'][$attempt->id]) => 'upper',
                $groups !== null && isset($groups['lower'][$attempt->id]) => 'lower',
                default => null,
            };
            foreach ($attempt->delivery ?? [] as $delivered) {
                $itemId = (int) $delivered['id'];
                $question = $delivered['question'];
                $type = (string) ($key[$itemId]['type'] ?? $question['type']['value']);
                $items[$itemId] ??= [
                    'id' => $itemId,
                    'type' => $type,
                    'typeLabel' => (string) ($question['type']['label'] ?? QuestionType::tryFrom($type)?->name ?? $type),
                    'prompt' => (string) ($question['prompt'] ?? ''),
                    'media' => $this->media($question['media'] ?? []),
                    'points' => (float) $delivered['points'],
                    'choices' => [],
                    'correctChoiceId' => null,
                    'delivered' => 0, 'answered' => 0, 'correct' => 0,
                    'graded' => 0, 'earnedPoints' => 0.0, 'gradedMaxPoints' => 0.0,
                    'groups' => ['upper' => ['delivered' => 0, 'score' => 0.0], 'lower' => ['delivered' => 0, 'score' => 0.0]],
                ];
                $entry = &$items[$itemId];
                $entry['delivered']++;
                $value = $answers[$itemId]['value'] ?? null;
                $isEssay = $type === QuestionType::Essay->value;

                if ($isEssay) {
                    if (is_string($value) && trim($value) !== '') {
                        $entry['answered']++;
                    }
                    $score = $scores[$itemId] ?? null;
                    $itemScore = null;
                    if (is_array($score) && ($score['status'] ?? null) === 'graded' && ($score['points'] ?? null) !== null) {
                        $entry['graded']++;
                        $entry['earnedPoints'] += (float) $score['points'];
                        $maxPoints = (float) ($score['max_points'] ?? $delivered['points']);
                        $entry['gradedMaxPoints'] += $maxPoints;
                        $itemScore = $maxPoints > 0 ? (float) $score['points'] / $maxPoints : null;
                    }
                } else {
                    // Snapshot choice order is kept unless choices were shuffled per attempt.
                    // Choice images (optional `image` key) are not part of the analysis.
                    foreach ($question['choices'] ?? [] as $choice) {
                        $entry['choices'][(int) $choice['id']] ??= ['id' => (int) $choice['id'], 'text' => (string) $choice['text'], 'count' => 0];
                    }
                    $correctId = isset($key[$itemId]['correct_choice_id']) ? (int) $key[$itemId]['correct_choice_id'] : null;
                    $entry['correctChoiceId'] ??= $correctId;
                    $isCorrect = false;
                    if (is_int($value) && isset($entry['choices'][$value])) {
                        $entry['answered']++;
                        $entry['choices'][$value]['count']++;
                        $isCorrect = $correctId !== null && $value === $correctId;
                    }
                    if ($isCorrect) {
                        $entry['correct']++;
                    }
                    $itemScore = $isCorrect ? 1.0 : 0.0;
                }

                if ($group !== null && $itemScore !== null) {
                    $entry['groups'][$group]['delivered']++;
                    $entry['groups'][$group]['score'] += $itemScore;
                }
                unset($entry);
            }
        }

        if ($examination->randomize_choices) {
            foreach ($items as &$entry) {
                ksort($entry['choices']);
            }
            unset($entry);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, int>  $positions
     * @return array<string, mixed>
     */
    private function present(array $item, Collection $positions, bool $discriminationAvailable): array
    {
        $isEssay = $item['type'] === QuestionType::Essay->value;
        $delivered = $item['delivered'];
        $difficulty = $isEssay
            ? ($item['gradedMaxPoints'] > 0 ? $this->round($item['earnedPoints'] * 100 / $item['gradedMaxPoints']) : null)
            : ($delivered > 0 ? $this->round($item['correct'] * 100 / $delivered) : null);
        $discrimination = null;
        if ($discriminationAvailable) {
            $upper = $item['groups']['upper'];
            $lower = $item['groups']['lower'];
            if ($upper['delivered'] > 0 && $lower['delivered'] > 0) {
                $discrimination = $this->round($upper['score'] / $upper['delivered'] - $lower['score'] / $lower['delivered']);
            }
        }

        $choices = [];
        $correctCount = 0;
        $strongestDistractor = 0;
        $index = 0;
        foreach ($item['choices'] as $choice) {
            $isCorrect = $choice['id'] === $item['correctChoiceId'];
            $choices[] = [
                'id' => $choice['id'],
                'label' => chr(ord('A') + $index++),
                'text' => $choice['text'],
                'isCorrect' => $isCorrect,
                'count' => $choice['count'],
                'percent' => $delivered > 0 ? $this->round($choice['count'] * 100 / $delivered) : null,
            ];
            if ($isCorrect) {
                $correctCount = $choice['count'];
            } else {
                $strongestDistractor = max($strongestDistractor, $choice['count']);
            }
        }

        $flags = [];
        if ($difficulty !== null && $difficulty < self::MISSED_BELOW_PERCENT) {
            $flags[] = ['code' => 'mostly_missed', 'label' => 'Most candidates missed this'];
        }
        if ($difficulty !== null && $difficulty > self::EASY_ABOVE_PERCENT) {
            $flags[] = ['code' => 'very_easy', 'label' => 'Very easy'];
        }
        if ($discrimination !== null && $discrimination < 0) {
            $flags[] = ['code' => 'negative_discrimination', 'label' => 'Negative discrimination — review the answer key'];
        }
        if (! $isEssay && $strongestDistractor > $correctCount) {
            $flags[] = ['code' => 'distractor_preferred', 'label' => 'A distractor was chosen more than the answer'];
        }

        return [
            'id' => $item['id'],
            'position' => $positions->get($item['id']),
            'type' => ['value' => $item['type'], 'label' => $item['typeLabel']],
            'prompt' => $item['prompt'],
            'media' => $item['media'],
            'points' => $this->round($item['points']),
            'delivered' => $delivered,
            'answered' => $item['answered'],
            'unanswered' => $delivered - $item['answered'],
            'unansweredPercent' => $delivered > 0 ? $this->round(($delivered - $item['answered']) * 100 / $delivered) : null,
            'correct' => $isEssay ? null : $item['correct'],
            'percentCorrect' => $isEssay ? null : $difficulty,
            'choices' => $choices,
            'essay' => $isEssay ? [
                'graded' => $item['graded'],
                'ungraded' => $delivered - $item['graded'],
                'averageScore' => $item['graded'] > 0 ? $this->round($item['earnedPoints'] / $item['graded']) : null,
                'maxPoints' => $this->round($item['points']),
                'averagePercent' => $difficulty,
            ] : null,
            'difficulty' => $difficulty,
            'discrimination' => $discrimination,
            'flags' => $flags,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $questions
     * @return Collection<int, array<string, mixed>>
     */
    private function sortQuestions(Collection $questions, string $sort): Collection
    {
        $order = fn (array $question): array => [$question['position'] === null ? 1 : 0, $question['position'] ?? 0, $question['id']];
        // Missing values sort last so the items needing attention come first.
        $metric = match ($sort) {
            self::SORT_MISSED => 'difficulty',
            self::SORT_DISCRIMINATION => 'discrimination',
            default => null,
        };

        return $questions->sort(function (array $a, array $b) use ($metric, $order): int {
            if ($metric !== null) {
                $byMetric = [$a[$metric] === null ? 1 : 0, $a[$metric] ?? 0] <=> [$b[$metric] === null ? 1 : 0, $b[$metric] ?? 0];
                if ($byMetric !== 0) {
                    return $byMetric;
                }
            }

            return $order($a) <=> $order($b);
        })->values();
    }

    /**
     * @param  Collection<int, ExaminationAttempt>  $attempts
     * @param  Collection<int, ExaminationAttempt>  $ranked
     * @return array<string, mixed>
     */
    private function summary(Examination $examination, Collection $attempts, Collection $ranked): array
    {
        $percentages = $ranked->map(fn (ExaminationAttempt $attempt): float => (float) $attempt->percentage)->sort()->values();
        $count = $percentages->count();
        $median = match (true) {
            $count === 0 => null,
            $count % 2 === 1 => $percentages[intdiv($count, 2)],
            default => ($percentages[$count / 2 - 1] + $percentages[$count / 2]) / 2,
        };
        $hasPassingScore = $examination->passing_score !== null;
        $decided = $ranked->filter(fn (ExaminationAttempt $attempt): bool => $attempt->passed !== null);

        return [
            'submittedAttempts' => $attempts->count(),
            'candidates' => $attempts->pluck('candidate_id')->unique()->count(),
            'scoredAttempts' => $count,
            'awaitingGrading' => $attempts->filter(fn (ExaminationAttempt $attempt): bool => $attempt->result_status !== 'graded')->count(),
            'mean' => $count === 0 ? null : $this->round($percentages->sum() / $count),
            'median' => $median === null ? null : $this->round($median),
            'highest' => $count === 0 ? null : $this->round($percentages->last()),
            'lowest' => $count === 0 ? null : $this->round($percentages->first()),
            'passingScore' => $hasPassingScore ? $this->round((float) $examination->passing_score) : null,
            'passed' => $hasPassingScore ? $decided->where('passed', true)->count() : null,
            'failed' => $hasPassingScore ? $decided->where('passed', false)->count() : null,
            'passRate' => $hasPassingScore && $decided->isNotEmpty() ? $this->round($decided->where('passed', true)->count() * 100 / $decided->count()) : null,
            // Final percentages by range, lowest first; empty when no attempt has a final score.
            'scoreDistribution' => $count === 0 ? [] : ScoreBands::columns(ScoreBands::count($percentages, withMissing: false)),
        ];
    }

    private function discriminationReason(int $submitted, int $ranked): string
    {
        $minimum = self::MIN_ATTEMPTS_FOR_DISCRIMINATION;
        if ($submitted < $minimum) {
            return "Discrimination needs at least {$minimum} submitted attempts; {$submitted} ".($submitted === 1 ? 'is' : 'are').' available. With fewer attempts the upper and lower groups are too small to be meaningful.';
        }

        return "Discrimination needs at least {$minimum} attempts with a final score; {$ranked} ".($ranked === 1 ? 'has' : 'have').' one. Attempts awaiting essay grading cannot be ranked yet.';
    }

    /**
     * Keeps only descriptive media fields; files are served by the
     * authorized staff media route.
     *
     * @return list<array{id: int, kind: string, description: string, mimeType: string, width: int|null, height: int|null}>
     */
    private function media(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }

        return collect($media)
            ->filter(fn (mixed $file): bool => is_array($file) && isset($file['id'], $file['kind']))
            ->map(fn (array $file): array => [
                'id' => (int) $file['id'],
                'kind' => (string) $file['kind'],
                'description' => (string) ($file['description'] ?? ''),
                'mimeType' => (string) ($file['mimeType'] ?? ''),
                'width' => isset($file['width']) ? (int) $file['width'] : null,
                'height' => isset($file['height']) ? (int) $file['height'] : null,
            ])->values()->all();
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
