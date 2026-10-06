<?php

namespace App\Models;

use App\Policies\ScheduleEntryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a class's training schedule (owner request, 2026-10-06): a
 * session on one date, or every week on the weekday of starts_on until
 * ends_on, from start_time to end_time (institution's local time). The
 * class is set when the entry is created and does not change. Change
 * through ScheduleService.
 */
#[Fillable(['title', 'location', 'starts_on', 'repeats_weekly', 'ends_on', 'start_time', 'end_time', 'notes'])]
#[UsePolicy(ScheduleEntryPolicy::class)]
class ScheduleEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_batch_id' => 'integer',
            'campus_id' => 'integer',
            'class_subject_id' => 'integer',
            'instructor_id' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'repeats_weekly' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return BelongsTo<ClassSubject, $this>
     */
    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * The dates the entry happens on between $from and $to (inclusive).
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = $this->starts_on;
        if (! $this->repeats_weekly) {
            return $first->betweenIncluded($from, $to) ? [$first] : [];
        }

        $last = $this->ends_on->min($to);
        // The first weekly date on or after $from.
        $date = $first->gte($from) ? $first : $from->addDays(($first->dayOfWeekIso - $from->dayOfWeekIso + 7) % 7);
        $dates = [];
        for (; $date->lte($last); $date = $date->addWeek()) {
            $dates[] = $date;
        }

        return $dates;
    }

    /** "08:00" from the stored "08:00:00". */
    public function startLabel(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endLabel(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }
}
