<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AccountExpenseRequest;
use App\Http\Requests\Accounts\AssignAccountExpenseRequest;
use App\Http\Requests\Accounts\VoidAccountEntryRequest;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\Candidate;
use App\Services\Accounts\AccountService;
use App\Support\AcademicOptions;
use App\Support\ListCharts;
use App\Support\Money;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expenses that finance staff define once and assign to a whole class or to
 * chosen candidates (route middleware: accounts.manage). Each assignment is
 * a charge entry for the candidate; a mistaken one is voided with a reason.
 */
class AccountExpenseController extends Controller
{
    private const PER_PAGE = 10;

    /** Most candidates listed at once in the assignment picker. */
    private const PICKER_LIMIT = 300;

    public function __construct(private readonly AccountService $accounts) {}

    public function index(): Response
    {
        $expenses = AccountExpense::query()
            ->with('category:id,name')
            ->withCount(['entries as assigned_count' => fn (Builder $entries) => $entries->standing()])
            ->withSum(['entries as assigned_total' => fn (Builder $entries) => $entries->standing()], 'amount')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $top = $expenses->sortByDesc(fn (AccountExpense $expense): float => (float) $expense->getAttribute('assigned_total'))->take(10);
        $charts = [
            ListCharts::bars('Total Assessed per Expense', 'The ten expenses with the highest total charged (voided charges left out).',
                $top->map(fn (AccountExpense $expense): array => ['label' => $expense->name, 'value' => Money::decimal(Money::toCents((string) $expense->getAttribute('assigned_total')))])->values()->all(), 'expense', 'expenses', 'money'),
            ListCharts::bars('Candidates Charged', 'How many candidates each of those expenses is charged to.',
                $top->map(fn (AccountExpense $expense): array => ['label' => $expense->name, 'value' => (int) $expense->getAttribute('assigned_count')])->values()->all(), 'candidate', 'candidates'),
        ];

        return Inertia::render('staff/accounts/expenses/index', [
            'charts' => $charts,
            'expenses' => $expenses->map(fn (AccountExpense $expense): array => [
                ...$this->present($expense),
                'assignedCount' => (int) $expense->getAttribute('assigned_count'),
                'assignedTotal' => Money::decimal(Money::toCents((string) $expense->getAttribute('assigned_total'))),
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/accounts/expenses/create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(AccountExpenseRequest $request): RedirectResponse
    {
        $data = $request->expenseData();
        unset($data['is_active']);
        $expense = $this->accounts->createExpense($data, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Expense {$expense->name} created. Assign it to a class or to candidates."]);

        return redirect()->route('accounts.expenses.show', $expense);
    }

    public function show(Request $request, AccountExpense $accountExpense): Response
    {
        $filters = [
            'class' => QueryFilters::id($request, 'class'),
            'search' => QueryFilters::search($request),
        ];

        $charges = $accountExpense->entries()
            ->with(['candidate:id,candidate_number,first_name,middle_name,last_name,suffix,class_batch_id', 'candidate.classBatch:id,name', 'category:id,name', 'voider:id,name'])
            ->join('candidates', 'candidates.id', '=', 'account_entries.candidate_id')
            ->select('account_entries.*')
            // Charges that count first, then voided ones; by candidate number within each.
            ->orderByRaw('account_entries.voided_at is not null')
            ->orderBy('candidates.candidate_number')
            ->orderBy('account_entries.id')
            ->paginate(self::PER_PAGE, pageName: 'page')
            ->withQueryString()
            ->through(fn (AccountEntry $entry): array => [
                'id' => $entry->id,
                'candidate' => [
                    'id' => $entry->candidate->id,
                    'candidateNumber' => $entry->candidate->candidate_number,
                    'name' => $entry->candidate->full_name,
                    'className' => $entry->candidate->classBatch?->name,
                ],
                'postedOn' => $entry->posted_on->toDateString(),
                'category' => $entry->category->name,
                'type' => ['value' => $entry->entry_type->value, 'label' => $entry->entry_type->label()],
                'amount' => Money::decimal(Money::toCents($entry->amount)),
                'description' => $accountExpense->name,
                'voided' => $entry->isVoided() ? ['at' => $entry->voided_at->toIso8601String(), 'by' => $entry->voider?->name, 'reason' => $entry->void_reason] : null,
            ]);

        $standing = $accountExpense->entries()->standing();

        return Inertia::render('staff/accounts/expenses/show', [
            'expense' => [
                ...$this->present($accountExpense->load('category:id,name', 'creator:id,name')),
                'createdBy' => $accountExpense->creator->name,
                'assignedCount' => (clone $standing)->count(),
                'assignedTotal' => Money::decimal(Money::toCents((string) (clone $standing)->sum('amount'))),
            ],
            'charges' => $charges,
            'filters' => $filters,
            'picker' => $this->picker($accountExpense, $filters),
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'today' => now()->timezone(config('institution.timezone'))->toDateString(),
            'maxCandidates' => AssignAccountExpenseRequest::MAX_CANDIDATES,
        ]);
    }

    public function edit(AccountExpense $accountExpense): Response
    {
        return Inertia::render('staff/accounts/expenses/edit', [
            'expense' => [...$this->present($accountExpense->load('category:id,name')), 'isAssigned' => $accountExpense->isAssigned()],
            'categories' => $this->categoryOptions($accountExpense->account_category_id),
        ]);
    }

    public function update(AccountExpenseRequest $request, AccountExpense $accountExpense): RedirectResponse
    {
        $this->accounts->updateExpense($accountExpense, $request->expenseData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Expense {$accountExpense->refresh()->name} updated."]);

        return redirect()->route('accounts.expenses.show', $accountExpense);
    }

    public function assign(AssignAccountExpenseRequest $request, AccountExpense $accountExpense): RedirectResponse
    {
        $candidates = $request->candidates();
        if ($candidates->isEmpty()) {
            return back()->withErrors(['class_batch_id' => 'This class has no candidates to charge (withdrawn candidates are left out).']);
        }

        $result = $this->accounts->assignExpense($accountExpense, $candidates, (string) $request->validated('posted_on'), $request->user());
        $assigned = $result['assigned']->count();
        $amount = Money::display(Money::toCents($accountExpense->amount));

        $message = $assigned === 0
            ? 'No new charges: every chosen candidate is already charged this expense.'
            : "{$accountExpense->name} ({$amount}) charged to {$assigned} ".($assigned === 1 ? 'candidate' : 'candidates').'.'
                .($result['skipped'] > 0 ? " {$result['skipped']} already charged ".($result['skipped'] === 1 ? 'was' : 'were').' left as they are.' : '');

        Inertia::flash('toast', ['type' => $assigned === 0 ? 'info' : 'success', 'message' => $message]);

        return redirect()->route('accounts.expenses.show', $accountExpense);
    }

    /** Voids a mistaken charge; it stays listed, struck through, and the candidate can be charged again. */
    public function void(VoidAccountEntryRequest $request, AccountEntry $accountEntry): RedirectResponse
    {
        $this->accounts->void($accountEntry, (string) $request->validated('reason'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Charge voided. It no longer counts.']);

        return back();
    }

    /**
     * Candidates to choose from, by class and/or search, with whether each
     * is already charged this expense. Nothing is listed until a class or a
     * search term narrows the list.
     *
     * @param  array{class: string, search: string}  $filters
     * @return array{candidates: list<array<string, mixed>>, truncated: bool}
     */
    private function picker(AccountExpense $expense, array $filters): array
    {
        if ($filters['class'] === '' && $filters['search'] === '') {
            return ['candidates' => [], 'truncated' => false];
        }

        $candidates = Candidate::query()
            ->with('classBatch:id,name')
            ->when($filters['class'] !== '', fn (Builder $query) => $query->where('class_batch_id', (int) $filters['class']))
            ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
            ->orderBy('candidate_number')
            ->orderBy('id')
            ->limit(self::PICKER_LIMIT + 1)
            ->get();

        $charged = $expense->entries()->standing()->whereIn('candidate_id', $candidates->modelKeys())->pluck('candidate_id')->all();

        return [
            'candidates' => $candidates->take(self::PICKER_LIMIT)->map(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
                'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                'charged' => in_array($candidate->id, $charged, true),
            ])->values()->all(),
            'truncated' => $candidates->count() > self::PICKER_LIMIT,
        ];
    }

    /**
     * Active categories, plus the expense's current one if it is no
     * longer active.
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(?int $currentId = null): array
    {
        return AccountCategory::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)))
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (AccountCategory $category): array => ['id' => $category->id, 'name' => $category->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AccountExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'name' => $expense->name,
            'category' => ['id' => $expense->account_category_id, 'name' => $expense->category->name],
            'amount' => Money::decimal(Money::toCents($expense->amount)),
            'description' => $expense->description,
            'isActive' => $expense->is_active,
        ];
    }
}
