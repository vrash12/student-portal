<?php

namespace App\Services\Accounts;

use App\Enums\AccountEntryType;
use App\Models\AccountEntry;
use App\Models\Candidate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Reads Statements of Account. The balance is charges minus credits over
 * entries that are not voided: positive means the candidate owes that
 * amount, negative means a credit in the candidate's favor. All sums are in
 * cents (AGENTS.md §15: one authoritative calculation).
 */
final class AccountLedger
{
    /** Signed amount of a standing entry in SQL: charges add, credits subtract. */
    private const SIGNED_AMOUNT = "case when entry_type = 'charge' then amount else -amount end";

    /** Values of the index balance filter. */
    public const BALANCE_FILTERS = ['due', 'credit', 'settled'];

    /**
     * The status of a balance, shown as text with its tone (never colour alone).
     *
     * @return array{value: string, label: string, tone: string}
     */
    public static function balanceStatus(int $cents): array
    {
        return match (true) {
            $cents > 0 => ['value' => 'due', 'label' => 'Balance due', 'tone' => 'warning'],
            $cents < 0 => ['value' => 'credit', 'label' => 'Credit balance', 'tone' => 'info'],
            default => ['value' => 'settled', 'label' => 'Settled', 'tone' => 'neutral'],
        };
    }

    /**
     * Adds charges_total, credits_total and balance columns to a candidate query.
     *
     * @param  Builder<Candidate>  $candidates
     * @return Builder<Candidate>
     */
    public function withTotals(Builder $candidates): Builder
    {
        return $candidates->addSelect([
            'charges_total' => $this->sum(AccountEntryType::Charge),
            'credits_total' => $this->sum(AccountEntryType::Credit),
            'balance' => $this->balanceQuery(),
        ]);
    }

    /**
     * Restricts a candidate query by balance: "due" (owes), "credit" (in
     * credit), "settled" (zero, including candidates without entries).
     *
     * @param  Builder<Candidate>  $candidates
     */
    public function whereBalance(Builder $candidates, string $filter): void
    {
        $operator = match ($filter) {
            'due' => '>',
            'credit' => '<',
            'settled' => '=',
            default => null,
        };

        if ($operator !== null) {
            $candidates->where($this->balanceQuery(), $operator, 0);
        }
    }

    /**
     * The candidate's statement for a date range: the balance brought
     * forward, every entry in the range (voided entries included, marked,
     * and not counted) with the running balance, and the totals.
     *
     * @return array{from: ?string, to: ?string, opening: string, charges: string, credits: string, closing: string, status: array{value: string, label: string, tone: string}, entries: list<array<string, mixed>>}
     */
    public function statement(Candidate $candidate, ?string $from, ?string $to): array
    {
        $openingCents = $from === null ? 0 : Money::toCents((string) AccountEntry::query()
            ->where('candidate_id', $candidate->id)
            ->standing()
            ->where('posted_on', '<', $from)
            // value() keeps the exact decimal string; sum() would return a float.
            ->selectRaw('coalesce(sum('.self::SIGNED_AMOUNT.'), 0) as total')
            ->value('total'));

        $entries = AccountEntry::query()
            ->where('candidate_id', $candidate->id)
            ->when($from !== null, fn (Builder $query) => $query->where('posted_on', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('posted_on', '<=', $to))
            ->with(['category:id,name', 'recorder:id,name', 'voider:id,name'])
            ->orderBy('posted_on')
            ->orderBy('id')
            ->get();

        $running = $openingCents;
        $charges = 0;
        $credits = 0;
        $rows = [];
        foreach ($entries as $entry) {
            $cents = Money::toCents($entry->amount);
            if (! $entry->isVoided()) {
                if ($entry->entry_type === AccountEntryType::Charge) {
                    $charges += $cents;
                    $running += $cents;
                } else {
                    $credits += $cents;
                    $running -= $cents;
                }
            }

            $rows[] = [
                'id' => $entry->id,
                'postedOn' => $entry->posted_on->toDateString(),
                'category' => $entry->category->name,
                'type' => ['value' => $entry->entry_type->value, 'label' => $entry->entry_type->label()],
                'amount' => Money::decimal($cents),
                'description' => $entry->description,
                'reference' => $entry->reference,
                'recordedBy' => $entry->recorder->name,
                'recordedAt' => $entry->created_at?->toIso8601String(),
                // Voided entries do not move the balance.
                'balance' => $entry->isVoided() ? null : Money::decimal($running),
                'voided' => $entry->isVoided() ? [
                    'at' => $entry->voided_at->toIso8601String(),
                    'by' => $entry->voider?->name,
                    'reason' => $entry->void_reason,
                ] : null,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'opening' => Money::decimal($openingCents),
            'charges' => Money::decimal($charges),
            'credits' => Money::decimal($credits),
            'closing' => Money::decimal($running),
            'status' => self::balanceStatus($running),
            'entries' => $rows,
        ];
    }

    /**
     * Institution-wide figures for the dashboard and the list page, with the
     * `$recent` most recently recorded entries that count.
     *
     * @return array{totalDue: string, candidatesDue: int, totalCredit: string, candidatesInCredit: int, recentEntries: list<array<string, mixed>>}
     */
    public function overview(int $recent = 5): array
    {
        $balances = DB::table('account_entries')
            ->whereNull('voided_at')
            ->groupBy('candidate_id')
            ->selectRaw('candidate_id, sum('.self::SIGNED_AMOUNT.') as balance');

        $totals = DB::query()->fromSub($balances, 'balances')->selectRaw(
            'coalesce(sum(case when balance > 0 then balance else 0 end), 0) as total_due, '
            .'coalesce(sum(case when balance > 0 then 1 else 0 end), 0) as candidates_due, '
            .'coalesce(sum(case when balance < 0 then -balance else 0 end), 0) as total_credit, '
            .'coalesce(sum(case when balance < 0 then 1 else 0 end), 0) as candidates_in_credit',
        )->first();

        return [
            'totalDue' => Money::decimal(Money::toCents((string) $totals->total_due)),
            'candidatesDue' => (int) $totals->candidates_due,
            'totalCredit' => Money::decimal(Money::toCents((string) $totals->total_credit)),
            'candidatesInCredit' => (int) $totals->candidates_in_credit,
            'recentEntries' => $recent <= 0 ? [] : AccountEntry::query()
                ->standing()
                ->with(['candidate:id,candidate_number,first_name,middle_name,last_name,suffix', 'category:id,name'])
                ->latest('id')
                ->limit($recent)
                ->get()
                ->map(fn (AccountEntry $entry): array => [
                    'id' => $entry->id,
                    'candidate' => ['id' => $entry->candidate->id, 'candidateNumber' => $entry->candidate->candidate_number, 'name' => $entry->candidate->full_name],
                    'category' => $entry->category->name,
                    'type' => ['value' => $entry->entry_type->value, 'label' => $entry->entry_type->label()],
                    'amount' => Money::decimal(Money::toCents($entry->amount)),
                    'postedOn' => $entry->posted_on->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function sum(AccountEntryType $type): QueryBuilder
    {
        return DB::table('account_entries')
            ->selectRaw('coalesce(sum(amount), 0)')
            ->whereColumn('account_entries.candidate_id', 'candidates.id')
            ->whereNull('voided_at')
            ->where('entry_type', $type->value);
    }

    private function balanceQuery(): QueryBuilder
    {
        return DB::table('account_entries')
            ->selectRaw('coalesce(sum('.self::SIGNED_AMOUNT.'), 0)')
            ->whereColumn('account_entries.candidate_id', 'candidates.id')
            ->whereNull('voided_at');
    }
}
