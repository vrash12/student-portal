<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Examinations\GradeEssayRequest;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationEssayRevision;
use App\Services\Examinations\ExaminationScoringService;
use App\Services\Examinations\ManualEssayGradingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ExaminationGradingController extends Controller
{
    public function index(Request $request, Examination $examination): Response
    {
        abort_unless($request->user()->hasPermission(Permission::RecordGrades) && $request->user()->teachesOffering($examination->class_subject_id), 403);
        $filter = $request->validate(['status' => 'sometimes|in:pending,graded,all', 'page' => 'sometimes|integer|min:1']);
        $status = $filter['status'] ?? 'pending';
        $attempts = $examination->attempts()->with('candidate')->where('status', 'submitted')
            ->when($status === 'pending', fn ($query) => $query->where(fn ($query) => $query->where('result_status', 'pending_review')->orWhereNull('result_status')))
            ->when($status === 'graded', fn ($query) => $query->where('result_status', 'graded'))
            ->orderBy('submitted_at')->orderBy('id')->paginate(20)->withQueryString();
        $attempts->through(fn (ExaminationAttempt $attempt) => [
            'id' => $attempt->id, 'number' => $attempt->attempt_number,
            'candidate' => $attempt->candidate->full_name, 'candidateNumber' => $attempt->candidate->candidate_number,
            'submittedAt' => $attempt->submitted_at?->toIso8601String(),
            'status' => $attempt->result_status ?? 'pending_review', 'percentage' => $attempt->percentage,
        ]);

        return Inertia::render('staff/examinations/grading', [
            'examination' => ['id' => $examination->id, 'title' => $examination->title],
            'attempts' => $attempts, 'status' => $status,
        ]);
    }

    public function show(Request $request, ExaminationAttempt $attempt, ExaminationScoringService $scoring): Response
    {
        Gate::authorize('grade', $attempt);
        abort_unless($attempt->status === 'submitted', 422, 'Only submitted attempts can be graded.');
        $attempt = $scoring->reconcile($attempt);
        $attempt->load(['candidate', 'examination', 'essayGrades.grader']);
        $grades = $attempt->essayGrades->keyBy('examination_question_id');
        $questions = collect($attempt->delivery)->filter(fn (array $item) => $item['question']['type']['value'] === 'essay')->map(function (array $item) use ($attempt, $grades) {
            $grade = $grades->get($item['id']);

            return [
                'id' => $item['id'], 'prompt' => $item['question']['prompt'],
                'answer' => $attempt->answers[$item['id']]['value'] ?? '', 'maxPoints' => $item['points'],
                'grade' => $grade?->score, 'comment' => $grade?->comment, 'version' => $grade?->version ?? 0,
                'grader' => $grade?->grader->name, 'gradedAt' => $grade?->graded_at?->toIso8601String(),
            ];
        })->values();
        $history = ExaminationEssayRevision::with('actor')->whereIn('examination_essay_grade_id', $grades->pluck('id'))->latest('id')->paginate(20, ['*'], 'history_page');
        $history->through(fn ($revision) => [
            'id' => $revision->id, 'itemId' => $grades->firstWhere('id', $revision->examination_essay_grade_id)->examination_question_id,
            'actor' => $revision->actor->name, 'at' => $revision->created_at->toIso8601String(),
            'before' => $revision->previous_score, 'after' => $revision->new_score,
            'commentBefore' => $revision->previous_comment, 'commentAfter' => $revision->new_comment, 'reason' => $revision->reason,
        ]);

        return Inertia::render('staff/examinations/grade-attempt', [
            'examination' => ['id' => $attempt->examination_id, 'title' => $attempt->examination->title],
            'attempt' => ['id' => $attempt->id, 'number' => $attempt->attempt_number, 'candidate' => $attempt->candidate->full_name, 'candidateNumber' => $attempt->candidate->candidate_number, 'status' => $attempt->result_status, 'percentage' => $attempt->percentage],
            'questions' => $questions, 'history' => $history, 'nextAttemptId' => $this->nextId($attempt),
        ]);
    }

    public function update(GradeEssayRequest $request, ExaminationAttempt $attempt, ManualEssayGradingService $service): RedirectResponse
    {
        $data = $request->validated();
        $attempt = $service->grade($request->user(), $attempt, (int) $data['item_id'], (string) $data['score'], $data['comment'] ?? null, (int) $data['version'], $data['reason'] ?? null);
        $nextId = $request->boolean('next') && $attempt->result_status === 'graded' ? $this->nextId($attempt) : null;

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Essay score saved.']);

        return redirect()->route('examination-attempts.grading', $nextId ?? $attempt->id);
    }

    private function nextId(ExaminationAttempt $attempt): ?int
    {
        return ExaminationAttempt::where('examination_id', $attempt->examination_id)->where('id', '!=', $attempt->id)
            ->where('status', 'submitted')->where(fn ($query) => $query->where('result_status', 'pending_review')->orWhereNull('result_status'))
            ->oldest('submitted_at')->orderBy('id')->value('id');
    }
}
