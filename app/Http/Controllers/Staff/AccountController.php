<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AccountEntryType;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AccountEntryRequest;
use App\Http\Requests\Accounts\VoidAccountEntryRequest;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\Candidate;
use App\Services\Accounts\AccountLedger;
use App\Services\Accounts\AccountService;
use App\Services\Accounts\StatementPdfService;
use App\Services\AuditLogger;
use App\Support\AcademicOptions;
use App\Support\CandidatePresenter;
use App\Support\Money;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statements of Account (accounts.view), recording and voiding entries
 * (accounts.manage). Permissions are enforced by route middleware. The
 * ledger records amounts only: no payments are processed.
 */
class AccountController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AccountLedger $ledger,
        private readonly AccountService $accounts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => QueryFilters::search($request),
            'class' => QueryFilters::id($request, 'class'),
            'balance' => QueryFilters::oneOf($request, 'balance', AccountLedger::BALANCE_FILTERS),
        ];

        $query = Candidate::query()->select('candidates.*')->with('classBatch:id,name');
        $this->ledger->withTotals($query);
        $this->ledger->whereBalance($query, $filters['balance']);

        $candidates = $query
            ->when($filters['search'] !== '', fn (Builder $candidates) => $candidates->matching($filters['search']))
            ->when($filters['class'] !== '', fn (Builder $candidates) => $candidates->where('class_batch_id', (int) $filters['class']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(function (Candidate $candidate): array {
                $balance = Money::toCents((string) $candidate->getAttribute('balance'));

                return [
                    'id' => $candidate->id,
                    'candidateNumber' => $candidate->candidate_number,
                    'name' => $candidate->full_name,
                    'className' => $candidate->classBatch?->name,
                    'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                    'charges' => Money::decimal(Money::toCents((string) $candidate->getAttribute('charges_total'))),
                    'credits' => Money::decimal(Money::toCents((string) $candidate->getAttribute('credits_total'))),
                    'balance' => Money::decimal($balance),
                    'balanceStatus' => AccountLedger::balanceStatus($balance),
                ];
            });

        return Inertia::render('staff/accounts/index', [
            'candidates' => $candidates,
            'filters' => $filters,
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'overview' => $this->ledger->overview(0),
            'can' => ['manage' => $request->user()->hasPermission(Permission::ManageAccounts)],
        ]);
    }

    public function show(Request $request, Candidate $candidate): Response
    {
        [$from, $to] = $this->period($request);
        $details = CandidatePresenter::details($candidate);

        return Inertia::render('staff/accounts/show', [
            // Only what identifies the candidate; the statement is not an academic record.
            'candidate' => [
                'id' => $details['id'],
                'candidateNumber' => $details['candidateNumber'],
                'name' => $details['name'],
                'status' => $details['status'],
                'classBatch' => $details['classBatch'] === null ? null : ['name' => $details['classBatch']['name'], 'period' => $details['classBatch']['period']],
            ],
            'statement' => $this->ledger->statement($candidate, $from, $to),
            'categories' => AccountCategory::query()->active()->ordered()->get()
                ->map(fn (AccountCategory $category): array => ['id' => $category->id, 'name' => $category->name, 'entryType' => $category->entry_type->value])->all(),
            'entryTypes' => AccountEntryType::options(),
            'today' => now()->timezone(config('institution.timezone'))->toDateString(),
            'can' => [
                'manage' => $request->user()->hasPermission(Permission::ManageAccounts),
                'viewCandidate' => $request->user()->can('view', $candidate),
            ],
        ]);
    }

    public function store(AccountEntryRequest $request, Candidate $candidate): RedirectResponse
    {
        $entry = $this->accounts->record($candidate, $request->entryData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$entry->entry_type->label()} of ".Money::display(Money::toCents($entry->amount)).' recorded.']);

        return back();
    }

    public function void(VoidAccountEntryRequest $request, AccountEntry $accountEntry): RedirectResponse
    {
        $this->accounts->void($accountEntry, (string) $request->validated('reason'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry voided. It no longer counts toward the balance.']);

        return back();
    }

    public function statement(Request $request, Candidate $candidate, StatementPdfService $pdf, AuditLogger $audit): HttpResponse
    {
        [$from, $to] = $this->period($request);
        $bytes = $pdf->render($candidate, $from, $to);
        $audit->record(AuditAction::AccountStatementDownloaded, $candidate, newValues: ['from' => $from, 'to' => $to]);
        $filename = 'statement-of-account-'.(Str::slug($candidate->candidate_number) ?: $candidate->id).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The statement's date range from the query string; invalid dates are a
     * validation error.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function period(Request $request): array
    {
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.date_format' => 'Enter the start date as a calendar date.',
            'to.date_format' => 'Enter the end date as a calendar date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        return [$dates['from'] ?? null, $dates['to'] ?? null];
    }
}
