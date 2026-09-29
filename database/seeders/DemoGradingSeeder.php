<?php

namespace Database\Seeders;

use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic grading data for local development and demonstrations: a
 * grading scheme for every taught subject of the active period, two
 * finalized assessments, one partly scored draft, and one upcoming
 * examination. Scores are deterministic and clearly fictional, and are
 * written through the grading services so revisions and audit entries exist.
 *
 * The weights below are demo data only; real grading rules are configured by
 * administrators in the application. Idempotent. Never runs in production.
 *
 * Requires DemoAcademicSeeder to have run first.
 */
class DemoGradingSeeder extends Seeder
{
    /**
     * Category name => weight (percent).
     */
    private const DEMO_SCHEME = [
        'Quizzes' => '20',
        'Examinations' => '30',
        'Practical Exercises' => '30',
        'Other Requirements' => '20',
    ];

    /**
     * Title, category, maximum score, date, whether to finalize, and the share
     * of candidates who have a score.
     *
     * @var list<array{title: string, category: string, max: string, date: string, finalize: bool, scored: float}>
     */
    private const DEMO_ASSESSMENTS = [
        ['title' => 'Quiz 1', 'category' => 'Quizzes', 'max' => '20', 'date' => '2026-08-21', 'finalize' => true, 'scored' => 1.0],
        ['title' => 'Practical Exercise 1', 'category' => 'Practical Exercises', 'max' => '100', 'date' => '2026-09-11', 'finalize' => true, 'scored' => 1.0],
        ['title' => 'Quiz 2', 'category' => 'Quizzes', 'max' => '25', 'date' => '2026-09-25', 'finalize' => false, 'scored' => 0.6],
        ['title' => 'Midterm Examination', 'category' => 'Examinations', 'max' => '100', 'date' => '2026-10-09', 'finalize' => false, 'scored' => 0.0],
    ];

    /**
     * Typical performance level per seat in a class, as a share of the
     * maximum score. Seat 5 is deliberately weak so monitoring has data.
     */
    private const SEAT_LEVELS = [0.93, 0.86, 0.79, 0.71, 0.58];

    public function __construct(
        private readonly GradingSchemeService $schemes,
        private readonly AssessmentService $assessments,
        private readonly ScoreRecordingService $scores,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo grading data must not be seeded in production.');
        }

        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return;
        }

        $offerings = ClassSubject::query()
            ->with(['subject', 'instructors'])
            ->whereHas('classBatch', fn ($classes) => $classes->where('academic_period_id', $period->id))
            ->whereHas('instructors')
            ->orderBy('id')
            ->get();

        foreach ($offerings as $offeringIndex => $offering) {
            // Idempotent: subjects that already have a grading setup are left alone.
            if ($offering->assessmentCategories()->exists()) {
                continue;
            }

            $candidates = Candidate::query()->gradableIn($offering->class_batch_id)->orderBy('candidate_number')->get();
            if ($candidates->isEmpty()) {
                continue;
            }

            $instructor = $offering->instructors->sortBy('id')->first();
            // One transaction per subject, so a failure never leaves a
            // half-seeded subject that later runs would skip.
            DB::transaction(fn () => $this->seedOffering($offering, $instructor, $candidates->all(), $offeringIndex));
        }
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    private function seedOffering(ClassSubject $offering, User $instructor, array $candidates, int $offeringIndex): void
    {
        $this->schemes->save($offering, array_map(
            fn (string $name, string $weight): array => ['id' => null, 'name' => $name, 'weight' => $weight],
            array_keys(self::DEMO_SCHEME),
            array_values(self::DEMO_SCHEME),
        ), reason: null);

        $categoryIds = $offering->assessmentCategories()->pluck('id', 'name');

        foreach (self::DEMO_ASSESSMENTS as $assessmentIndex => $definition) {
            $assessment = $this->assessments->create($offering, [
                'title' => $definition['title'],
                'assessment_category_id' => (int) $categoryIds[$definition['category']],
                'max_score' => $definition['max'],
                'assessed_on' => $definition['date'],
            ], $instructor);

            $entries = $this->entries($assessment, $candidates, $definition['scored'], $offeringIndex, $assessmentIndex);
            if ($entries !== []) {
                $this->scores->recordDraftScores($assessment, $entries, $instructor);
            }

            // Finalizing needs at least one score (a class of one may only
            // have the excused absence).
            $hasScore = array_filter($entries, fn (array $entry): bool => $entry['score'] !== null) !== [];
            if ($definition['finalize'] && $hasScore) {
                $this->assessments->finalize($assessment, $instructor);
            }
        }
    }

    /**
     * @param  list<Candidate>  $candidates
     * @return array<int, array{score: ?string, comment: ?string, expected_score: ?string, expected_comment: ?string}>
     */
    private function entries(Assessment $assessment, array $candidates, float $scoredShare, int $offeringIndex, int $assessmentIndex): array
    {
        $scoredCount = (int) round(count($candidates) * $scoredShare);
        $maxScore = (float) $assessment->max_score;
        $entries = [];

        foreach (array_slice($candidates, 0, $scoredCount) as $seat => $candidate) {
            // The first candidate of the second subject misses the practical
            // exercise, so the demo shows a Missing Scores status.
            if ($offeringIndex === 1 && $assessmentIndex === 1 && $seat === 0) {
                $entries[$candidate->id] = ['score' => null, 'comment' => 'Absent (excused)', 'expected_score' => null, 'expected_comment' => null];

                continue;
            }

            $level = self::SEAT_LEVELS[$seat % count(self::SEAT_LEVELS)];
            // Deterministic variation of -5 to +5 percentage points.
            $variation = ((($seat * 7) + ($offeringIndex * 3) + ($assessmentIndex * 5)) % 11 - 5) / 100;
            $share = max(0.0, min(1.0, $level + $variation));
            // Scores in half points.
            $score = round($maxScore * $share * 2) / 2;

            $entries[$candidate->id] = [
                'score' => DecimalValue::normalize($score),
                'comment' => null,
                'expected_score' => null,
                'expected_comment' => null,
            ];
        }

        return $entries;
    }
}
