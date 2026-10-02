<?php

namespace App\Http\Controllers\Staff;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionBank\QuestionRequest;
use App\Models\Question;
use App\Models\QuestionTopic;
use App\Models\Subject;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionPresenter;
use App\Support\ListCharts;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The question bank of the subjects the user teaches (Milestone 7).
 *
 * Authorization is on the routes (question_bank.manage and QuestionPolicy);
 * question rules are enforced by QuestionBankService. Questions reach the
 * browser only through QuestionPresenter: list rows never contain choices,
 * answers, or explanations, and the preview and edit pages (which do) are
 * limited to staff who teach the question's subject.
 */
class QuestionBankController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly QuestionBankService $questions,
        private readonly QuestionPresenter $presenter,
    ) {}

    /**
     * Questions of the taught subjects, newest first. Filter values outside
     * the user's subjects (or topics of another subject) are ignored and
     * echoed back as empty.
     */
    public function index(Request $request): Response
    {
        $taughtIds = $request->user()->taughtSubjectIds();

        $subject = $this->allowedId($request, 'subject', $taughtIds);
        // Topics follow the selected subject: its topics that have questions.
        $topics = $subject === '' ? [] : $this->topicOptions((int) $subject);
        $topic = $this->allowedId($request, 'topic', array_column($topics, 'id'));

        $filters = [
            'search' => QueryFilters::search($request),
            'subject' => $subject,
            'topic' => $topic,
            'type' => QueryFilters::oneOf($request, 'type', array_map(fn (QuestionType $type): string => $type->value, QuestionType::cases())),
            'status' => QueryFilters::oneOf($request, 'status', ['active', 'inactive']),
        ];

        $query = Question::query()
            ->with(QuestionPresenter::SUMMARY_RELATIONS)
            ->whereIn('subject_id', $subject === '' ? $taughtIds : [(int) $subject])
            ->when($topic !== '', fn (Builder $query) => $query->where('question_topic_id', (int) $topic))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where('prompt', 'like', QueryFilters::likeTerm($filters['search'])));

        $subjectNames = Subject::query()->whereIn('id', $taughtIds)->pluck('name', 'id');
        $charts = [
            ListCharts::pie('Questions by Type', 'Matching questions by question type.',
                ListCharts::countBy($query, 'type', fn (mixed $value): string => QuestionType::tryFrom((string) $value)?->label() ?? (string) $value), 'question', 'questions'),
            ListCharts::bars('Questions by Subject', 'Matching questions in each of your subjects.',
                ListCharts::countBy($query, 'subject_id', fn (mixed $value): string => (string) ($subjectNames[$value] ?? 'Subject')), 'question', 'questions'),
        ];

        $questions = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Question $question): array => $this->presenter->summary($question));

        return Inertia::render('staff/question-bank/index', [
            'questions' => $questions,
            'charts' => $charts,
            'filters' => $filters,
            'subjects' => $this->subjectOptions($taughtIds),
            'topics' => $topics,
            'types' => QuestionType::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        $taughtIds = $request->user()->taughtSubjectIds();
        $requested = $this->allowedId($request, 'subject', $taughtIds);

        return Inertia::render('staff/question-bank/create', [
            'subjects' => $this->subjectsWithTopics($taughtIds),
            // The requested subject, or the only one the user teaches.
            'selectedSubjectId' => $requested !== '' ? (int) $requested : (count($taughtIds) === 1 ? $taughtIds[0] : null),
            ...$this->formOptions(),
        ]);
    }

    public function store(QuestionRequest $request): RedirectResponse
    {
        $question = $this->questions->create($request->subject(), $request->questionData(), $request->user());

        // "Save and Add Another": a new, empty form for the same subject.
        if ($request->boolean('add_another')) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Question saved. Add the next one.']);

            return redirect()->route('question-bank.create', ['subject' => $question->subject_id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Question saved.']);

        return redirect()->route('question-bank.show', $question);
    }

    /**
     * Preview with the correct answer marked, the staff-only explanation, and
     * the question's record.
     */
    public function show(Question $question): Response
    {
        $question->load([...QuestionPresenter::STAFF_RELATIONS, 'creator:id,name', 'updater:id,name']);

        return Inertia::render('staff/question-bank/show', [
            'question' => $this->presenter->staff($question),
            'record' => [
                'lockedAt' => $question->locked_at?->toIso8601String(),
                'createdBy' => $question->creator->name,
                'createdAt' => $question->created_at?->toIso8601String(),
                // updated_by and updated_at record content edits only.
                'updatedBy' => $question->updater?->name,
                'updatedAt' => $question->updated_by === null ? null : $question->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function edit(Question $question): Response
    {
        $question->load(QuestionPresenter::STAFF_RELATIONS);

        return Inertia::render('staff/question-bank/edit', [
            'question' => $this->presenter->staff($question),
            'topics' => QuestionTopic::query()->where('subject_id', $question->subject_id)->orderBy('name')->pluck('name')->all(),
            ...$this->formOptions(),
        ]);
    }

    public function update(QuestionRequest $request, Question $question): RedirectResponse
    {
        $changed = $this->questions->update($question, $request->questionData(), $request->user());

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'Question saved.']
            : ['type' => 'info', 'message' => 'No changes to save.']);

        return redirect()->route('question-bank.show', $question);
    }

    public function activate(Request $request, Question $question): RedirectResponse
    {
        $changed = $this->questions->activate($question, $request->user());

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'Question activated. It can be added to examinations again.']
            : ['type' => 'info', 'message' => 'This question is already active.']);

        return redirect()->route('question-bank.show', $question);
    }

    public function deactivate(Request $request, Question $question): RedirectResponse
    {
        $changed = $this->questions->deactivate($question, $request->user());

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'Question deactivated. It can no longer be added to examinations.']
            : ['type' => 'info', 'message' => 'This question is already inactive.']);

        return redirect()->route('question-bank.show', $question);
    }

    public function duplicate(Request $request, Question $question): RedirectResponse
    {
        $copy = $this->questions->duplicate($question, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Copy created. You are editing the copy; the original question is unchanged.']);

        return redirect()->route('question-bank.edit', $copy);
    }

    /**
     * Question types and input limits for the create and edit forms.
     *
     * @return array{types: list<array{value: string, label: string}>, limits: array<string, int>}
     */
    private function formOptions(): array
    {
        return [
            'types' => QuestionType::options(),
            'limits' => [
                'minChoices' => QuestionType::MIN_CHOICES,
                'maxChoices' => QuestionType::MAX_CHOICES,
                'promptLength' => QuestionBankService::PROMPT_MAX_LENGTH,
                'choiceLength' => QuestionBankService::CHOICE_MAX_LENGTH,
                'topicLength' => QuestionBankService::TOPIC_MAX_LENGTH,
                'explanationLength' => QuestionBankService::EXPLANATION_MAX_LENGTH,
            ],
        ];
    }

    /**
     * @param  list<int>  $subjectIds
     * @return list<array{id: int, code: string, name: string}>
     */
    private function subjectOptions(array $subjectIds): array
    {
        return Subject::query()
            ->whereKey($subjectIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'code', 'name'])
            ->map(fn (Subject $subject): array => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name])
            ->values()
            ->all();
    }

    /**
     * Taught subjects with their topic names, for the create form's subject
     * select and topic suggestions.
     *
     * @param  list<int>  $subjectIds
     * @return list<array{id: int, code: string, name: string, topics: list<string>}>
     */
    private function subjectsWithTopics(array $subjectIds): array
    {
        $topics = QuestionTopic::query()
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('name')
            ->get(['subject_id', 'name'])
            ->groupBy('subject_id');

        return array_map(fn (array $subject): array => [
            ...$subject,
            'topics' => $topics->get($subject['id'])?->pluck('name')->values()->all() ?? [],
        ], $this->subjectOptions($subjectIds));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function topicOptions(int $subjectId): array
    {
        return QuestionTopic::query()
            ->where('subject_id', $subjectId)
            ->whereHas('questions')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (QuestionTopic $topic): array => ['id' => $topic->id, 'name' => $topic->name])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $allowed
     */
    private function allowedId(Request $request, string $key, array $allowed): string
    {
        $value = QueryFilters::id($request, $key);

        return $value !== '' && in_array((int) $value, $allowed, true) ? $value : '';
    }
}
