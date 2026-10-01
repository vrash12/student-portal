<?php

namespace App\Services\Conduct;

use App\Models\Candidate;
use App\Models\ConductEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads merits and demerits. Totals are the sums of points over entries that
 * are not voided; net = merits − demerits. This is the only place conduct
 * points are added up (AGENTS.md §15): the conduct pages, the candidate
 * profile, the portal and the qualification engine all read it.
 */
final class ConductLedger
{
    /** Candidate ids per query, well under placeholder limits. */
    private const CHUNK = 1000;

    /**
     * Merit, demerit and net points per candidate. Every requested id is
     * present, with zeros when the candidate has no standing entries.
     *
     * @param  array<int, int|string>  $candidateIds
     * @return array<int, array{merits: int, demerits: int, net: int}>
     */
    public function totalsFor(array $candidateIds): array
    {
        $ids = array_values(array_unique(array_map(fn (int|string $id): int => (int) $id, $candidateIds)));
        $totals = array_fill_keys($ids, ['merits' => 0, 'demerits' => 0, 'net' => 0]);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('conduct_entries')
                ->whereIn('candidate_id', $chunk)
                ->whereNull('voided_at')
                ->groupBy('candidate_id')
                ->selectRaw("candidate_id, sum(case when kind = 'merit' then points else 0 end) as merits, sum(case when kind = 'demerit' then points else 0 end) as demerits")
                ->get();

            foreach ($rows as $row) {
                $merits = (int) $row->merits;
                $demerits = (int) $row->demerits;
                $totals[(int) $row->candidate_id] = ['merits' => $merits, 'demerits' => $demerits, 'net' => $merits - $demerits];
            }
        }

        return $totals;
    }

    /**
     * The candidate's entries, newest first (by date, then by recording).
     * Voided entries are included and marked unless `$includeVoided` is
     * false (the candidate portal leaves them out).
     *
     * @return list<array{id: int, occurredOn: string, kind: array{value: string, label: string, tone: string}, type: string, points: int, reason: string, recordedBy: string, recordedAt: ?string, voided: array{at: string, by: ?string, reason: ?string}|null}>
     */
    public function history(Candidate $candidate, ?int $limit = null, bool $includeVoided = true): array
    {
        return ConductEntry::query()
            ->where('candidate_id', $candidate->id)
            ->when(! $includeVoided, fn (Builder $entries) => $entries->standing())
            ->with(['type:id,name', 'recorder:id,name', 'voider:id,name'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->when($limit !== null, fn (Builder $entries) => $entries->limit(max(0, (int) $limit)))
            ->get()
            ->map(fn (ConductEntry $entry): array => [
                'id' => $entry->id,
                'occurredOn' => $entry->occurred_on->toDateString(),
                'kind' => $entry->kind->toArray(),
                'type' => $entry->type->name,
                'points' => $entry->points,
                'reason' => $entry->reason,
                'recordedBy' => $entry->recorder->name,
                'recordedAt' => $entry->created_at?->toIso8601String(),
                // Voided entries do not count toward the totals.
                'voided' => $entry->isVoided() ? [
                    'at' => $entry->voided_at->toIso8601String(),
                    'by' => $entry->voider?->name,
                    'reason' => $entry->void_reason,
                ] : null,
            ])
            ->values()
            ->all();
    }
}
