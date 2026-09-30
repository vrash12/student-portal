<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\AssessmentRequest;
use App\Models\Assessment;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\Gradebook;
use App\Support\DecimalValue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assessments of a class subject and their score sheets. Authorization is
 * applied on the routes (ClassSubjectPolicy / AssessmentPolicy); draft and
 * finalized rules are enforced by AssessmentService.
 */
class AssessmentController extends Controller
{
    private const HISTORY_LIMIT = 50;

    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly Gradebook $gradebook,
    ) {}

    public function create(ClassBatch $classBatch, ClassSubject $classSubject): Response
    {
        return Inertia::render('staff/teaching/assessments/create', [
            'offering' => $this->gradebook->offering($classSubject),
            'categories' => $this->gradebook->scheme($classSubject),
        ]);
    }

    public function store(AssessmentRequest $request, ClassBatch $classBatch, ClassSubject $classSubject): RedirectResponse
    {
        $assessment = $this->assessments->create($classSubject, $request->assessmentData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$assessment->title} created. Record scores below."]);

        return redirect()->route('assessments.show', $assessment);
    }

    public function show(Request $request, Assessment $assessment): Response
    {
        $assessment->load(['classSubject', 'category', 'creator:id,name', 'finalizer:id,name']);

        return Inertia::render('staff/teaching/assessments/show', [
            'offering' => $this->gradebook->offering($assessment->classSubject),
            'assessment' => [
                ...$this->details($assessment),
                'createdBy' => $assessment->creator->name,
                'finalizedBy' => $assessment->finalizer?->name,
                'finalizedAt' => $assessment->finalized_at?->toIso8601String(),
                'sourceExaminationId' => $assessment->source_examination_id,
                'examAttemptRule' => $assessment->exam_attempt_rule,
            ],
            'roster' => $this->gradebook->scoreSheet($assessment),
            'history' => $this->gradebook->history($assessment, self::HISTORY_LIMIT),
            'can' => [
                'manage' => $request->user()->can('manage', $assessment),
            ],
        ]);
    }

    public function edit(Assessment $assessment): Response|RedirectResponse
    {
        if (! $assessment->isDraft()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Finalized assessments cannot be edited.']);

            return redirect()->route('assessments.show', $assessment);
        }

        $assessment->load(['classSubject', 'category']);
        $highest = $assessment->scores()->max('score');

        return Inertia::render('staff/teaching/assessments/edit', [
            'offering' => $this->gradebook->offering($assessment->classSubject),
            'categories' => $this->gradebook->scheme($assessment->classSubject),
            'assessment' => [
                ...$this->details($assessment),
                'highestScore' => $highest === null ? null : DecimalValue::display($highest),
            ],
        ]);
    }

    public function update(AssessmentRequest $request, Assessment $assessment): RedirectResponse
    {
        $this->assessments->update($assessment, $request->assessmentData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Assessment details updated.']);

        return redirect()->route('assessments.show', $assessment);
    }

    public function destroy(Request $request, Assessment $assessment): RedirectResponse
    {
        $assessment->loadMissing('classSubject');
        $offering = $assessment->classSubject;
        $title = $assessment->title;

        $this->assessments->delete($assessment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$title} deleted."]);

        return redirect()->route('teaching.gradebooks.show', [$offering->class_batch_id, $offering->id]);
    }

    public function finalize(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->assessments->finalize($assessment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$assessment->title} finalized. Its scores now count toward grades."]);

        return redirect()->route('assessments.show', $assessment);
    }

    /**
     * @return array{id: int, title: string, category: array{id: int, name: string, weight: string}, maxScore: string, assessedOn: string|null, status: array{value: string, label: string, tone: string}}
     */
    private function details(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'category' => [
                'id' => $assessment->category->id,
                'name' => $assessment->category->name,
                'weight' => DecimalValue::display($assessment->category->weight),
            ],
            'maxScore' => DecimalValue::display($assessment->max_score),
            'assessedOn' => $assessment->assessed_on?->toDateString(),
            'status' => $assessment->status->toArray(),
        ];
    }
}
