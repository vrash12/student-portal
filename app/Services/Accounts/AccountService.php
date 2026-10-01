<?php

namespace App\Services\Accounts;

use App\Enums\AccountEntryType;
use App\Enums\AuditAction;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records Statement of Account entries, manages their categories, and
 * assigns expenses to candidates. Entries are never edited or deleted: a
 * mistaken entry is voided with a reason and the correct one recorded.
 * Every change is audited.
 */
final class AccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{account_category_id: int, entry_type: string, amount: string, posted_on: string, due_on?: ?string, description: string, reference: ?string}  $data
     */
    public function record(Candidate $candidate, array $data, User $actor): AccountEntry
    {
        return DB::transaction(function () use ($candidate, $data, $actor): AccountEntry {
            $category = AccountCategory::query()->active()->find($data['account_category_id'])
                ?? throw ValidationException::withMessages(['account_category_id' => 'Choose an active category.']);

            $entry = new AccountEntry;
            $entry->forceFill([
                'candidate_id' => $candidate->id,
                'account_category_id' => $category->id,
                'entry_type' => $data['entry_type'],
                'amount' => Money::decimal(Money::toCents($data['amount'])),
                'posted_on' => $data['posted_on'],
                // Only charges fall due.
                'due_on' => $data['entry_type'] === AccountEntryType::Charge->value ? ($data['due_on'] ?? null) : null,
                'description' => $data['description'],
                'reference' => $data['reference'],
                'recorded_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::AccountEntryRecorded, $entry, newValues: [
                'candidate' => $candidate->candidate_number,
                'category' => $category->name,
                ...$this->snapshot($entry),
            ], actor: $actor);

            return $entry;
        });
    }

    public function void(AccountEntry $entry, string $reason, User $actor): AccountEntry
    {
        return DB::transaction(function () use ($entry, $reason, $actor): AccountEntry {
            $locked = AccountEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['reason' => 'This entry has already been voided.']);
            }

            $locked->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

            $this->audit->record(AuditAction::AccountEntryVoided, $locked, oldValues: $this->snapshot($locked), reason: $reason, actor: $actor);

            return $locked;
        });
    }

    /**
     * @param  array{name: string, account_category_id: int, amount: string, due_on: ?string, description: ?string}  $data
     */
    public function createExpense(array $data, User $actor): AccountExpense
    {
        return DB::transaction(function () use ($data, $actor): AccountExpense {
            $expense = new AccountExpense;
            $expense->forceFill([
                ...$data,
                'amount' => Money::decimal(Money::toCents($data['amount'])),
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::AccountExpenseCreated, $expense, newValues: $this->expenseSnapshot($expense), actor: $actor);

            return $expense;
        });
    }

    /**
     * Updates an expense. Once it is charged to a candidate, its amount and
     * category are fixed (the charges already carry them); the name, due date
     * and description still apply to every charge of the expense.
     *
     * @param  array{name: string, account_category_id: int, amount: string, due_on: ?string, description: ?string, is_active: bool}  $data
     */
    public function updateExpense(AccountExpense $expense, array $data, User $actor): AccountExpense
    {
        return DB::transaction(function () use ($expense, $data): AccountExpense {
            $locked = AccountExpense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $before = $this->expenseSnapshot($locked);
            $amount = Money::decimal(Money::toCents($data['amount']));

            if ($locked->isAssigned()) {
                $message = 'This expense is already charged to candidates, so its amount and category cannot change. Void those charges first, or create a new expense.';
                if ($amount !== Money::decimal(Money::toCents($locked->amount))) {
                    throw ValidationException::withMessages(['amount' => $message]);
                }
                if ($data['account_category_id'] !== $locked->account_category_id) {
                    throw ValidationException::withMessages(['account_category_id' => $message]);
                }
            }

            $locked->forceFill([...Arr::except($data, 'is_active'), 'amount' => $amount, 'is_active' => $data['is_active']])->save();

            $this->audit->recordChanges(AuditAction::AccountExpenseUpdated, $locked, $before, $this->expenseSnapshot($locked->refresh()));

            return $locked;
        });
    }

    /**
     * Charges an expense to each candidate who does not already have a
     * standing charge for it (a voided charge does not count): one charge
     * entry per candidate with the expense's amount, dated `$postedOn`.
     * The expense row is locked, so two assignments at once cannot charge a
     * candidate twice; a unique key in the database enforces the same rule.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @return array{assigned: Collection<int, Candidate>, skipped: int}
     */
    public function assignExpense(AccountExpense $expense, Collection $candidates, string $postedOn, User $actor): array
    {
        return DB::transaction(function () use ($expense, $candidates, $postedOn, $actor): array {
            $locked = AccountExpense::query()->whereKey($expense->id)->lockForUpdate()->with('category')->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['expense' => 'This expense is inactive. Reactivate it before assigning it.']);
            }

            $alreadyCharged = $locked->entries()->standing()->whereIn('candidate_id', $candidates->modelKeys())->pluck('candidate_id')->all();
            $assigned = $candidates->reject(fn (Candidate $candidate): bool => in_array($candidate->id, $alreadyCharged, true))->values();

            $amount = Money::decimal(Money::toCents($locked->amount));
            $now = now();
            foreach ($assigned->chunk(200) as $chunk) {
                AccountEntry::query()->insert($chunk->map(fn (Candidate $candidate): array => [
                    'candidate_id' => $candidate->id,
                    'account_category_id' => $locked->account_category_id,
                    'account_expense_id' => $locked->id,
                    'entry_type' => AccountEntryType::Charge->value,
                    'amount' => $amount,
                    'posted_on' => $postedOn,
                    'description' => $locked->name,
                    'recorded_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            if ($assigned->isNotEmpty()) {
                $this->audit->record(AuditAction::AccountExpenseAssigned, $locked, newValues: [
                    'expense' => $locked->name,
                    'category' => $locked->category->name,
                    'amount' => $amount,
                    'posted_on' => $postedOn,
                    'candidate_count' => $assigned->count(),
                    'candidates' => $assigned->pluck('candidate_number')->all(),
                ], actor: $actor);
            }

            return ['assigned' => $assigned, 'skipped' => $candidates->count() - $assigned->count()];
        });
    }

    /**
     * @param  array{name: string, entry_type: string, description: ?string, sort_order: int}  $data
     */
    public function createCategory(array $data): AccountCategory
    {
        return DB::transaction(function () use ($data): AccountCategory {
            $category = AccountCategory::query()->create($data);
            $this->audit->record(AuditAction::AccountCategoryCreated, $category, newValues: $this->categorySnapshot($category));

            return $category;
        });
    }

    /**
     * @param  array{name: string, entry_type: string, description: ?string, sort_order: int, is_active: bool}  $data
     */
    public function updateCategory(AccountCategory $category, array $data): AccountCategory
    {
        return DB::transaction(function () use ($category, $data): AccountCategory {
            $before = $this->categorySnapshot($category);
            $category->fill(Arr::except($data, 'is_active'));
            $category->is_active = $data['is_active'];
            $category->save();

            $this->audit->recordChanges(AuditAction::AccountCategoryUpdated, $category, $before, $this->categorySnapshot($category->refresh()));

            return $category;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AccountEntry $entry): array
    {
        return [
            'entry_type' => $entry->entry_type->value,
            'amount' => (string) $entry->amount,
            'posted_on' => $entry->posted_on->toDateString(),
            'due_on' => $entry->due_on?->toDateString(),
            'expense_id' => $entry->account_expense_id,
            'description' => $entry->description,
            'reference' => $entry->reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseSnapshot(AccountExpense $expense): array
    {
        return [
            'name' => $expense->name,
            'account_category_id' => $expense->account_category_id,
            'amount' => (string) $expense->amount,
            'due_on' => $expense->due_on?->toDateString(),
            'description' => $expense->description,
            'is_active' => $expense->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(AccountCategory $category): array
    {
        return [
            'name' => $category->name,
            'entry_type' => $category->entry_type->value,
            'description' => $category->description,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
        ];
    }
}
