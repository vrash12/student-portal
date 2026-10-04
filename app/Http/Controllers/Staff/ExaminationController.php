<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ExaminationKind;
use App\Enums\ExaminationStatus;
use App\Http\Requests\ExaminationRequest;
use App\Http\Requests\QuestionBank\ExaminationQuestionRequest;
use App\Models\Assessment;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\Question;
use App\Models\QuestionTopic;
use App\Services\Examinations\ExaminationMonitoringService;
use App\Services\Examinations\ExaminationService;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionPresenter;
use App\Support\ListCharts;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class ExaminationController
{
    /** Status filter of the list: the lifecycle shown in each row (Examination::lifecycle). */
    private const LIFECYCLE_FILTERS = ['draft' => 'Draft', 'upcoming' => 'Published (not open yet)', 'active' => 'Active', 'ended' => 'Ended', 'archived' => 'Archived'];

    public function index(Request $request)
    {
        abort_unless($request->user()->canTeach(), 403);
        $query = Examination::with('classSubject.subject', 'classSubject.classBatch')
            ->whereHas('classSubject.instructorAssignments', fn ($query) => $query->where('instructor_id', $request->user()->id));
        $charts = [
            ListCharts::pie('By Status', 'Your quizzes and examinations by status.',
                ListCharts::countBy($query, 'status', fn (mixed $value): string => ExaminationStatus::tryFrom((string) $value)?->label() ?? (string) $value), 'examination', 'examinations'),
            ListCharts::pie('Quizzes and Examinations', 'How many of each kind you have created.',
                ListCharts::countBy($query, 'kind', fn (mixed $value): string => ExaminationKind::tryFrom((string) $value)?->label() ?? (string) $value), 'item', 'items'),
        ];
        // Filters (owner request 2026-10-05). Only the instructor's own class subjects are offered; anything else is ignored.
        $offerings = ClassSubject::with('subject', 'classBatch')
            ->whereHas('instructorAssignments', fn ($assignments) => $assignments->where('instructor_id', $request->user()->id))
            ->get()
            ->sortBy(fn (ClassSubject $offering): string => $offering->classBatch->name.' '.$offering->subject->name)
            ->values();
        $filters = [
            'search' => QueryFilters::search($request),
            'status' => QueryFilters::oneOf($request, 'status', array_keys(self::LIFECYCLE_FILTERS)),
            'kind' => QueryFilters::oneOf($request, 'kind', ExaminationKind::values()),
            'offering' => in_array((int) QueryFilters::id($request, 'offering'), $offerings->modelKeys(), true) ? QueryFilters::id($request, 'offering') : '',
        ];
        $now = now();
        $filtered = (clone $query)
            ->when($filters['search'] !== '', fn ($exams) => $exams->where('title', 'like', QueryFilters::likeTerm($filters['search'])))
            ->when($filters['kind'] !== '', fn ($exams) => $exams->where('kind', $filters['kind']))
            ->when($filters['offering'] !== '', fn ($exams) => $exams->where('class_subject_id', (int) $filters['offering']))
            ->when($filters['status'] !== '', fn ($exams) => match ($filters['status']) {
                'draft' => $exams->where('status', ExaminationStatus::Draft->value),
                'archived' => $exams->where('status', ExaminationStatus::Archived->value),
                // Published, the same rules as Examination::lifecycle().
                'upcoming' => $exams->where('status', ExaminationStatus::Published->value)->where('opens_at', '>', $now)->where(fn ($open) => $open->whereNull('closes_at')->orWhere('closes_at', '>', $now)),
                'active' => $exams->where('status', ExaminationStatus::Published->value)
                    ->where(fn ($open) => $open->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
                    ->where(fn ($open) => $open->whereNull('closes_at')->orWhere('closes_at', '>', $now)),
                'ended' => $exams->where('status', ExaminationStatus::Published->value)->where('closes_at', '<=', $now),
            });
        $exams = $filtered->latest()->paginate(10)->withQueryString()->through(fn ($exam) => $exam->toArray() + ['lifecycle' => $exam->lifecycle()]);

        return Inertia::render('staff/examinations/index', [
            'examinations' => $exams,
            'charts' => $charts,
            'filters' => $filters,
            'total' => (clone $query)->count(),
            'statusOptions' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::LIFECYCLE_FILTERS), self::LIFECYCLE_FILTERS),
            'kindOptions' => array_map(fn (ExaminationKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], ExaminationKind::cases()),
            'offeringOptions' => $offerings->map(fn (ClassSubject $offering): array => ['id' => $offering->id, 'name' => $offering->classBatch->name.' · '.$offering->subject->name])->all(),
        ]);
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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Next, write its questions.']);

        // Step 2 of the builder: writing or choosing questions.
        return redirect('/examinations/'.$exam->id.'/questions');
    }

    public function edit(Examination $examination, Request $request)
    {
        Gate::authorize('view', $examination);
        if ($examination->status !== ExaminationStatus::Draft) {
            return $this->onlyDrafts($examination, 'settings');
        }
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
        if ($examination->status !== ExaminationStatus::Draft) {
            return $this->onlyDrafts($examination, 'questions');
        }
        $examination->load('classSubject.subject', 'examinationQuestions');
        $subject = $examination->classSubject->subject;
        $questions = Question::with(QuestionPresenter::STAFF_RELATIONS)->where('subject_id', $subject->id)->where('is_active', true)->orderByDesc('id')->get()->map(fn ($question) => $presenter->staff($question));

        return Inertia::render('staff/examinations/questions', [
            'examination' => $examination,
            'questions' => $questions,
            // Write a New Question: the question form for the examination's subject.
            'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name],
            'topics' => QuestionTopic::query()->where('subject_id', $subject->id)->orderBy('name')->pluck('name')->all(),
            ...QuestionBankService::formOptions(),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Write a New Question from the builder: saved to the question bank of the
     * examination's subject and added as the draft's last question.
     */
    public function storeQuestion(ExaminationQuestionRequest $request, Examination $examination, ExaminationService $service)
    {
        $service->addNewQuestion($request->user(), $examination, $request->questionData());
        $count = $examination->examinationQuestions()->count();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Question saved and added as question {$count}."]);

        return redirect('/examinations/'.$examination->id.'/questions');
    }

    public function syncQuestions(Request $request, Examination $examination, ExaminationService $service)
    {
        $data = $request->validate(['questions' => 'present|array|max:'.ExaminationService::MAX_QUESTIONS, 'questions.*.question_id' => 'required|integer|distinct', 'questions.*.points' => 'required|numeric|decimal:0,2|min:.01|max:100']);
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

    /**
     * The settings and questions of an examination change only while it is a
     * draft. An old link or the Back button leads to the examination itself
     * with an explanation, never to an error page.
     */
    private function onlyDrafts(Examination $examination, string $part): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'warning', 'message' => "This examination is no longer a draft, so its {$part} cannot be changed."]);

        return redirect('/examinations/'.$examination->id);
    }
}
