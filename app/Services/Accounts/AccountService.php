<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records Statement of Account entries and manages their categories.
 * Entries are never edited or deleted: a mistaken entry is voided with a
 * reason and the correct one recorded. Every change is audited.
 */
final class AccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{account_category_id: int, entry_type: string, amount: string, posted_on: string, description: string, reference: ?string}  $data
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
            'description' => $entry->description,
            'reference' => $entry->reference,
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
