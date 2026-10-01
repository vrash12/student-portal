<?php

namespace App\Support;

use App\Models\Candidate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company and platoon names in use. They are free text on the candidate
 * record, so filter options are built from the values actually recorded
 * rather than from a fixed list.
 */
final class CandidateGroups
{
    /** Longest company or platoon name (the column length). */
    public const MAX_LENGTH = 50;

    /**
     * Distinct non-empty company names, naturally sorted ("2nd" before "10th").
     *
     * @return list<string>
     */
    public static function companies(?int $classBatchId = null): array
    {
        return self::distinct('company', $classBatchId);
    }

    /**
     * Distinct non-empty platoon names, naturally sorted.
     *
     * @return list<string>
     */
    public static function platoons(?int $classBatchId = null): array
    {
        return self::distinct('platoon', $classBatchId);
    }

    /**
     * @return list<string>
     */
    private static function distinct(string $column, ?int $classBatchId): array
    {
        $values = Candidate::query()
            ->when($classBatchId !== null, fn (Builder $query) => $query->where('class_batch_id', $classBatchId))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->pluck($column)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($values);
    }
}
