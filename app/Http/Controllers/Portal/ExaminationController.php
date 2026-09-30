<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\QuestionMediaController;
use App\Http\Requests\Portal\SaveAttemptRequest;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\QuestionMedia;
use App\Services\Examinations\CandidateAttemptService;
use App\Services\Examinations\ExaminationFocusService;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExaminationController extends Controller
{
    public function show(Request $request, Examination $examination, CandidateAttemptService $service): Response
    {
        abort_unless($service->eligible($request->user(), $examination), 403);
        $attempts = ExaminationAttempt::where('examination_id', $examination->id)->where('candidate_id', $request->user()->candidate->id)->get();
        $attempts = $attempts->map(fn (ExaminationAttempt $attempt): ExaminationAttempt => $service->expire($attempt));

        return Inertia::render('portal/examinations/show', ['examination' => ['id' => $examination->id, 'title' => $examination->title, 'description' => $examination->description, 'durationMinutes' => $examination->duration_minutes, 'questionCount' => $examination->examinationQuestions()->count(), 'attemptLimit' => $examination->attempt_limit, 'attemptsUsed' => $attempts->count(), 'requiresCode' => $examination->access_code !== null, 'available' => $examination->isActive(), 'resumeId' => $attempts->firstWhere('status', 'in_progress')?->id, 'allowBackNavigation' => $examination->allow_back_navigation, 'releaseResults' => $examination->release_results, 'attempts' => $attempts->sortByDesc('attempt_number')->values()->map(fn (ExaminationAttempt $item) => ['number' => $item->attempt_number, 'status' => $item->status, 'submittedAt' => $item->submitted_at?->toIso8601String(), 'resultStatus' => $item->result_status, 'percentage' => $examination->release_results ? $item->percentage : null])->all()]]);
    }

    public function start(Request $request, Examination $examination, CandidateAttemptService $service): RedirectResponse
    {
        $data = $request->validate(['access_code' => 'nullable|string|max:100']);
        $attempt = $service->start($request->user(), $examination, $data['access_code'] ?? null);

        return redirect()->route('portal.attempts.show', $attempt);
    }

    public function attempt(Request $request, ExaminationAttempt $attempt, CandidateAttemptService $service): Response|RedirectResponse
    {
        $attempt = $service->read($request->user(), $attempt);
        if ($attempt->status !== 'in_progress') {
            return redirect()->route('portal.attempts.success', $attempt);
        }
        abort_unless($service->eligible($request->user(), $attempt->examination), 403);
        $exam = $attempt->examination;

        return Inertia::render('portal/examinations/attempt', ['attempt' => ['id' => $attempt->id, 'title' => $exam->title, 'expiresAt' => $attempt->expires_at->toIso8601String(), 'serverNow' => now()->toIso8601String(), 'allowBackNavigation' => $exam->allow_back_navigation, 'oneQuestionAtATime' => $exam->one_question_at_a_time, 'position' => $attempt->current_position, 'revision' => $attempt->revision, 'answers' => (object) $attempt->answers], 'questions' => $attempt->delivery]);
    }

    public function save(SaveAttemptRequest $request, ExaminationAttempt $attempt, CandidateAttemptService $service): JsonResponse
    {
        $attempt = $service->save($request->user(), $attempt, $request->validated());

        return response()->json(['revision' => $attempt->revision, 'status' => $attempt->status]);
    }

    public function activity(Request $request, ExaminationAttempt $attempt, CandidateAttemptService $service): JsonResponse
    {
        $attempt = $service->heartbeat($request->user(), $attempt);

        return response()->json(['status' => $attempt->status, 'serverNow' => now()->toIso8601String(), 'expiresAt' => $attempt->expires_at?->toIso8601String()]);
    }

    /**
     * A question's image, audio, or video during the candidate's own attempt:
     * only while it is in progress and only media of its delivered questions.
     */
    public function media(Request $request, ExaminationAttempt $attempt, QuestionMedia $medium, QuestionMediaService $media): BinaryFileResponse
    {
        // No row lock: video seeking sends many range requests, which must not contend with answer saves.
        Gate::authorize('view', $attempt);
        abort_unless($attempt->status === 'in_progress' && $attempt->expires_at?->isFuture(), 404);
        $delivered = collect($attempt->delivery)->flatMap(fn (array $item): array => array_column($item['question']['media'] ?? [], 'id'));
        abort_unless($delivered->contains($medium->id), 404);

        return QuestionMediaController::file($media, $medium);
    }

    /**
     * The candidate left or returned to the examination screen. Recorded for
     * instructors as an indicator only; it never changes answers or scores.
     */
    public function focus(Request $request, ExaminationAttempt $attempt, ExaminationFocusService $service): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:left,returned'],
            'reason' => ['required', 'string', 'in:hidden,blur'],
            'delay_ms' => ['sometimes', 'integer', 'min:0', 'max:86400000'],
        ]);

        return response()->json($service->record($request->user(), $attempt, $data['event'], $data['reason'], (int) ($data['delay_ms'] ?? 0)));
    }

    public function submit(SaveAttemptRequest $request, ExaminationAttempt $attempt, CandidateAttemptService $service): RedirectResponse
    {
        $attempt = $service->save($request->user(), $attempt, $request->validated(), true);

        return redirect()->route('portal.attempts.success', $attempt);
    }

    public function success(Request $request, ExaminationAttempt $attempt, CandidateAttemptService $service): Response
    {
        $attempt = $service->read($request->user(), $attempt);
        abort_if($attempt->status === 'in_progress', 404);

        return Inertia::render('portal/examinations/success', ['title' => $attempt->examination->title, 'status' => $attempt->status, 'attemptId' => $attempt->id, 'releaseResults' => $attempt->examination->release_results, 'resultStatus' => $attempt->result_status, 'objectivePoints' => $attempt->examination->release_results ? $attempt->objective_points : null, 'objectiveMaxPoints' => $attempt->examination->release_results ? $attempt->objective_max_points : null, 'percentage' => $attempt->examination->release_results ? $attempt->percentage : null, 'passed' => $attempt->examination->release_results ? $attempt->passed : null]);
    }
}
