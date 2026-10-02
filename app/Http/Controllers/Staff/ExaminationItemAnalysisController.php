<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Services\Examinations\ItemAnalysisService;
use App\Support\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item analysis of an examination. Authorized exactly like its essay
 * grading: the route requires examinations.manage, and the instructor must
 * record grades for and teach the examination's class subject.
 */
final class ExaminationItemAnalysisController extends Controller
{
    private const SCOPE_LABELS = [
        ItemAnalysisService::SCOPE_LATEST => 'Latest attempt per candidate',
        ItemAnalysisService::SCOPE_ALL => 'All submitted attempts',
    ];

    private const SORT_LABELS = [
        ItemAnalysisService::SORT_MISSED => 'Most missed first',
        ItemAnalysisService::SORT_ORDER => 'Examination order',
        ItemAnalysisService::SORT_DISCRIMINATION => 'Lowest discrimination first',
    ];

    public function show(Request $request, Examination $examination, ItemAnalysisService $analysis): Response
    {
        [$filters, $details] = $this->prepare($request, $examination);

        return Inertia::render('staff/examinations/analysis', [
            'examination' => $details,
            'analysis' => $analysis->analyse($examination, $filters['scope'], $filters['sort']),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /** The analysis with the same choices, as a PDF file (Save as PDF): totals only, never individual answers. */
    public function pdf(Request $request, Examination $examination, ItemAnalysisService $service): HttpResponse
    {
        [$filters, $details] = $this->prepare($request, $examination);
        $analysis = $service->analyse($examination, $filters['scope'], $filters['sort']);
        $summary = $analysis['summary'];
        abort_if($summary['submittedAttempts'] === 0, 404);

        $stats = [
            ['Submitted attempts', (string) $summary['submittedAttempts']],
            ['Candidates', (string) $summary['candidates']],
            ['Awaiting essay grading', (string) $summary['awaitingGrading']],
            ['With a final score', (string) $summary['scoredAttempts']],
            ['Mean', PdfReport::percent($summary['mean'])],
            ['Median', PdfReport::percent($summary['median'])],
            ['Highest', PdfReport::percent($summary['highest'])],
            ['Lowest', PdfReport::percent($summary['lowest'])],
        ];
        if ($summary['passingScore'] !== null) {
            $stats[] = ['Pass rate', PdfReport::percent($summary['passRate'])."\n".($summary['passed'] ?? 0).' of '.$summary['scoredAttempts'].' reached '.PdfReport::percent($summary['passingScore'])];
        }

        $sections = [['type' => 'fields', 'heading' => 'Summary', 'perRow' => 4, 'fields' => $stats,
            'note' => 'Percentages are final examination scores. Attempts awaiting essay grading have no final score yet and are excluded from these figures.']];
        if (! $analysis['discrimination']['available'] && $analysis['discrimination']['reason']) {
            $sections[] = ['type' => 'alert', 'text' => 'Discrimination index not shown: '.$analysis['discrimination']['reason']];
        }
        if ($analysis['undeliveredQuestions'] > 0) {
            $sections[] = ['type' => 'alert', 'text' => $analysis['undeliveredQuestions'].' question(s) of this examination were not delivered in any counted attempt and are not listed.'];
        }

        $sections[] = [
            'type' => 'table',
            'heading' => 'Questions',
            'columns' => [
                ['label' => 'Question', 'width' => '12%'], ['label' => 'Type', 'width' => '12%'],
                ['label' => 'Delivered', 'width' => '9%', 'numeric' => true], ['label' => 'Answered', 'width' => '9%', 'numeric' => true],
                ['label' => 'Unanswered', 'width' => '10%', 'numeric' => true], ['label' => 'Correct / average', 'width' => '11%', 'numeric' => true],
                ['label' => 'Discrimination', 'width' => '12%', 'numeric' => true], ['label' => 'Review notes'],
            ],
            'rows' => array_map(fn (array $question): array => [
                self::questionName($question), $question['type']['label'],
                (string) $question['delivered'], (string) $question['answered'], (string) $question['unanswered'],
                PdfReport::percent($question['difficulty']), self::discrimination($question['discrimination']),
                self::flags($question['flags']),
            ], $analysis['questions']),
            'note' => 'Ordered: '.mb_strtolower(self::SORT_LABELS[$analysis['sort']]).'. Percent correct counts unanswered deliveries as not correct; for essays it is the average score of graded responses.',
        ];

        foreach ($analysis['questions'] as $index => $question) {
            array_push($sections, ...self::questionDetail($question, $index === 0));
        }

        return PdfReport::download(
            $request->user(),
            'Item Analysis',
            "{$details['title']} · {$details['kind']} · {$details['subject']} · {$details['classBatch']}",
            [['Attempts counted', self::SCOPE_LABELS[$analysis['scope']]], ['Order', self::SORT_LABELS[$analysis['sort']]]],
            $sections,
            'item-analysis-'.$details['title'],
        );
    }

    /**
     * @return array{0: array{scope: string, sort: string}, 1: array<string, mixed>}
     */
    private function prepare(Request $request, Examination $examination): array
    {
        abort_unless($request->user()->hasPermission(Permission::RecordGrades) && $request->user()->teachesOffering($examination->class_subject_id), 403);
        $filters = $request->validate([
            'scope' => ['sometimes', Rule::in(ItemAnalysisService::SCOPES)],
            'sort' => ['sometimes', Rule::in(ItemAnalysisService::SORTS)],
        ]);
        $examination->loadMissing('classSubject.subject', 'classSubject.classBatch');

        return [
            ['scope' => $filters['scope'] ?? ItemAnalysisService::SCOPE_LATEST, 'sort' => $filters['sort'] ?? ItemAnalysisService::SORT_MISSED],
            [
                'id' => $examination->id,
                'title' => $examination->title,
                'kind' => $examination->kind?->label() ?? 'Examination',
                'subject' => $examination->classSubject->subject->name,
                'classBatch' => $examination->classSubject->classBatch->name,
            ],
        ];
    }

    /**
     * One question's figures and, for objective questions, how often each choice was chosen.
     *
     * @param  array<string, mixed>  $question
     * @return list<array<string, mixed>>
     */
    private static function questionDetail(array $question, bool $first): array
    {
        $essay = $question['essay'];
        $points = $question['points'].' '.((float) $question['points'] === 1.0 ? 'point' : 'points');
        $figures = [
            ['Delivered', (string) $question['delivered']], ['Answered', (string) $question['answered']], ['Unanswered', (string) $question['unanswered']],
            ...($essay !== null ? [
                ['Graded', $essay['graded'].' of '.$question['delivered']],
                ['Average score', $essay['averageScore'] === null ? '—' : PdfReport::number($essay['averageScore']).' / '.PdfReport::number($essay['maxPoints'])],
                ['Average percent', PdfReport::percent($essay['averagePercent'])],
            ] : [
                ['Correct', (string) ($question['correct'] ?? 0)],
                ['Percent correct', PdfReport::percent($question['percentCorrect'])],
            ]),
            ['Discrimination', self::discrimination($question['discrimination'])],
        ];

        $text = $question['prompt']."\n\n".implode('   ', array_map(fn (array $figure): string => "{$figure[0]}: {$figure[1]}", $figures));
        if ($question['flags'] !== []) {
            $text .= "\nReview notes: ".self::flags($question['flags'], ', ');
        }
        if ($essay !== null && $essay['ungraded'] > 0) {
            $text .= "\n{$essay['ungraded']} response(s) not graded yet and not included in the average.";
        }
        if ($question['media'] !== []) {
            $text .= "\nThis question includes ".count($question['media']).' image or media file(s), shown in the system.';
        }

        $section = ['type' => 'text', 'heading' => ($first ? 'Question Details — ' : '').self::questionName($question).' · '.$question['type']['label'].' · '.$points, 'text' => $text, 'keep' => $essay !== null];
        if ($essay !== null) {
            return [$section];
        }

        return [$section, [
            'type' => 'table',
            'keep' => true,
            'columns' => [['label' => 'Choice', 'width' => '58%'], ['label' => 'Answer key', 'width' => '18%'], ['label' => 'Chosen', 'width' => '12%', 'numeric' => true], ['label' => 'Share', 'numeric' => true]],
            'rows' => [
                ...array_map(fn (array $choice): array => ["{$choice['label']}. {$choice['text']}", $choice['isCorrect'] ? 'Correct answer' : 'Distractor', (string) $choice['count'], PdfReport::percent($choice['percent'])], $question['choices']),
                ['No answer', 'Not correct', (string) $question['unanswered'], PdfReport::percent($question['unansweredPercent'])],
            ],
        ]];
    }

    /** @param  array<string, mixed>  $question */
    private static function questionName(array $question): string
    {
        return $question['position'] === null ? 'Removed question' : 'Question '.$question['position'];
    }

    /** Discrimination index from −1 to 1, two decimals, with a true minus sign. */
    private static function discrimination(?float $value): string
    {
        return $value === null ? '—' : ($value < 0 ? '−'.number_format(abs($value), 2) : number_format($value, 2));
    }

    /** @param  list<array{code: string, label: string}>  $flags */
    private static function flags(array $flags, string $separator = "\n"): string
    {
        return $flags === [] ? 'None' : implode($separator, array_column($flags, 'label'));
    }
}
