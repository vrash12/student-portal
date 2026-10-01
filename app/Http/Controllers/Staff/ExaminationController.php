<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ExaminationStatus;
use App\Http\Requests\ExaminationRequest;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\Question;
use App\Services\Examinations\ExaminationMonitoringService;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class ExaminationController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canTeach(), 403);
        $exams = Examination::with('classSubject.subject', 'classSubject.classBatch')
            ->whereHas('classSubject.instructorAssignments', fn ($query) => $query->where('instructor_id', $request->user()->id))
            ->latest()->paginate(20)->through(fn ($exam) => $exam->toArray() + ['lifecycle' => $exam->lifecycle()]);

        return Inertia::render('staff/examinations/index', ['examinations' => $exams]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->canTeach(), 403);
        $offerings = ClassSubject::with('subject', 'classBatch')->whereHas('instructorAssignments', fn ($query) => $query->where('instructor_id', $request->user()->id))->get();

        return Inertia::render('staff/examinations/create', ['offerings' => $offerings]);
    }

    public function store(ExaminationRequest $request, ExaminationService $service)
    {
        $exam = $service->create($request->user(), $request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Next, choose its questions.']);

        // Step 2 of the builder: choosing questions.
        return redirect('/examinations/'.$exam->id.'/questions');
    }

    public function edit(Examination $examination, Request $request)
    {
        Gate::authorize('view', $examination);
        abort_unless($examination->status === ExaminationStatus::Draft, 422);
        $examination->load('classSubject.subject', 'classSubject.classBatch');

        // Access codes are shown only to this assigned instructor in the settings form.
        return Inertia::render('staff/examinations/create', ['examination' => array_merge($examination->toArray(), ['access_code' => $examination->access_code, 'opens_at' => $examination->opens_at?->timezone(config('institution.timezone'))->format('Y-m-d\\TH:i'), 'closes_at' => $examination->closes_at?->timezone(config('institution.timezone'))->format('Y-m-d\\TH:i')]), 'offerings' => [$examination->classSubject]])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function update(ExaminationRequest $request, Examination $examination, ExaminationService $service)
    {
        $service->update($request->user(), $examination, $request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Examination settings saved.']);

        return redirect('/examinations/'.$examination->id);
    }

    public function show(Examination $examination, Request $request, ExaminationMonitoringService $monitoring, QuestionPresenter $presenter)
    {
        Gate::authorize('view', $examination);
        $examination->load('classSubject.subject', 'classSubject.classBatch', 'examinationQuestions.question.choices');
        $payload = $examination->toArray();
        $payload['lifecycle'] = $examination->lifecycle();
        $payload['has_access_code'] = $examination->access_code !== null;
        $payload['can_post_grades'] = $request->user()->can('recordGrades', $examination->classSubject);
        $payload['posted_assessment_id'] = Assessment::where('source_examination_id', $examination->id)->value('id');
        $payload['examination_questions'] = $examination->examinationQuestions->map(fn ($item) => ['id' => $item->id, 'points' => $item->points, 'question' => $presenter->staff($item->question)])->all();

        return Inertia::render('staff/examinations/show', ['examination' => $payload, 'monitoring' => fn () => $monitoring->snapshot($examination)])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function questions(Examination $examination, Request $request, QuestionPresenter $presenter)
    {
        Gate::authorize('view', $examination);
        abort_unless($examination->status === ExaminationStatus::Draft, 422);
        $examination->load('classSubject', 'examinationQuestions');
        $questions = Question::with(QuestionPresenter::STAFF_RELATIONS)->where('subject_id', $examination->classSubject->subject_id)->where('is_active', true)->orderByDesc('id')->get()->map(fn ($question) => $presenter->staff($question));

        return Inertia::render('staff/examinations/questions', ['examination' => $examination, 'questions' => $questions])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function syncQuestions(Request $request, Examination $examination, ExaminationService $service)
    {
        $data = $request->validate(['questions' => 'present|array|max:500', 'questions.*.question_id' => 'required|integer|distinct', 'questions.*.points' => 'required|numeric|decimal:0,2|min:.01|max:100']);
        $service->syncQuestions($request->user(), $examination, $data['questions']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Questions and order saved.']);

        return redirect('/examinations/'.$examination->id);
    }

    public function publish(Request $request, Examination $examination, ExaminationService $service)
    {
        $service->publish($request->user(), $examination);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Examination published.']);

        return back();
    }

    public function archive(Request $request, Examination $examination, ExaminationService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $service->archive($request->user(), $examination, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Examination archived.']);

        return back();
    }

    public function releaseResults(Request $request, Examination $examination, ExaminationService $service)
    {
        $data = $request->validate(['release_results' => 'required|boolean', 'reason' => 'required|string|max:500']);
        $service->releaseResults($request->user(), $examination, (bool) $data['release_results'], $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Result visibility updated.']);

        return back();
    }
}
