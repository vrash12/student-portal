<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SaveAttemptRequest;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Services\Examinations\CandidateAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExaminationController extends Controller
{
    public function show(Request $request, Examination $examination, CandidateAttemptService $service): Response
    {
        abort_unless($service->eligible($request->user(), $examination), 403);
        $attempts = ExaminationAttempt::where('examination_id', $examination->id)->where('candidate_id', $request->user()->candidate->id)->get();

        return Inertia::render('portal/examinations/show', ['examination' => ['id' => $examination->id, 'title' => $examination->title, 'description' => $examination->description, 'durationMinutes' => $examination->duration_minutes, 'questionCount' => $examination->examinationQuestions()->count(), 'attemptLimit' => $examination->attempt_limit, 'attemptsUsed' => $attempts->count(), 'requiresCode' => $examination->access_code !== null, 'available' => $examination->isActive(), 'resumeId' => $attempts->firstWhere('status', 'in_progress')?->id, 'allowBackNavigation' => $examination->allow_back_navigation]]);
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

    public function submit(SaveAttemptRequest $request, ExaminationAttempt $attempt, CandidateAttemptService $service): RedirectResponse
    {
        $attempt = $service->save($request->user(), $attempt, $request->validated(), true);

        return redirect()->route('portal.attempts.success', $attempt);
    }

    public function success(Request $request, ExaminationAttempt $attempt, CandidateAttemptService $service): Response
    {
        $attempt = $service->read($request->user(), $attempt);
        abort_if($attempt->status === 'in_progress', 404);

        return Inertia::render('portal/examinations/success', ['title' => $attempt->examination->title, 'status' => $attempt->status]);
    }
}
