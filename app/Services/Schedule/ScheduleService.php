<?php

namespace App\Services\Schedule;

use App\Enums\AuditAction;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes and removes entries of a class's training schedule (owner
 * request, 2026-10-06). The subject must be one of the class's subjects, the
 * instructor teaching staff of the class's campus, and every date inside
 * the class's academic year. Every change is in the audit log.
 */
final class ScheduleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{class_subject_id: ?int, instructor_id: ?int, title: string, location: ?string, starts_on: string, repeats_weekly: bool, ends_on: ?string, start_time: string, end_time: string, notes: ?string}  $details
     */
    public function create(ClassBatch $classBatch, array $details, User $actor): ScheduleEntry
    {
        return DB::transaction(function () use ($classBatch, $details, $actor): ScheduleEntry {
            $this->validateFor($classBatch, $details);

            $entry = new ScheduleEntry($this->attributes($details));
            $entry->class_batch_id = $classBatch->id;
            $entry->campus_id = (int) $classBatch->campus_id;
            $entry->class_subject_id = $details['class_subject_id'];
            $entry->instructor_id = $details['instructor_id'];
            $entry->created_by = $actor->id;
            $entry->save();

            $this->audit->record(AuditAction::ScheduleEntryCreated, $entry, newValues: ['class' => $classBatch->name, ...$this->snapshot($entry)], actor: $actor);

            return $entry;
        });
    }

    /**
     * Changes an entry; its class never changes. Weekly entries change on
     * every date, past ones included (the schedule is a plan, not a record).
     *
     * @param  array{class_subject_id: ?int, instructor_id: ?int, title: string, location: ?string, starts_on: string, repeats_weekly: bool, ends_on: ?string, start_time: string, end_time: string, notes: ?string}  $details
     */
    public function update(ScheduleEntry $entry, array $details, User $actor): ScheduleEntry
    {
        return DB::transaction(function () use ($entry, $details, $actor): ScheduleEntry {
            $locked = ScheduleEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->validateFor($locked->classBatch()->firstOrFail(), $details);

            $before = $this->snapshot($locked);
            $locked->fill($this->attributes($details));
            $locked->class_subject_id = $details['class_subject_id'];
            $locked->instructor_id = $details['instructor_id'];
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->recordChanges(AuditAction::ScheduleEntryUpdated, $locked, $before, $this->snapshot($locked->refresh()));

            return $locked;
        });
    }

    public function delete(ScheduleEntry $entry, User $actor): void
    {
        DB::transaction(function () use ($entry, $actor): void {
            $locked = ScheduleEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->audit->record(AuditAction::ScheduleEntryDeleted, $locked, oldValues: [
                'class' => $locked->classBatch()->value('name'),
                ...$this->snapshot($locked),
            ], actor: $actor);
            $locked->delete();
        });
    }

    /**
     * @param  array{class_subject_id: ?int, instructor_id: ?int, starts_on: string, repeats_weekly: bool, ends_on: ?string}  $details
     */
    private function validateFor(ClassBatch $classBatch, array $details): void
    {
        $errors = [];

        if ($details['class_subject_id'] !== null && ! ClassSubject::query()->whereKey($details['class_subject_id'])->where('class_batch_id', $classBatch->id)->exists()) {
            $errors['class_subject_id'] = 'Choose one of the subjects of this class.';
        }

        if ($details['instructor_id'] !== null) {
            $instructor = User::query()->find($details['instructor_id']);
            if ($instructor === null || ! $instructor->is_active || ! $instructor->canTeach() || (int) $instructor->getAttribute('campus_id') !== (int) $classBatch->campus_id) {
                $errors['instructor_id'] = 'Choose an active instructor of the class\'s campus.';
            }
        }

        $period = $classBatch->academicPeriod()->firstOrFail();
        $first = $period->starts_on->toDateString();
        $last = $period->ends_on->toDateString();
        $ends = $details['repeats_weekly'] ? $details['ends_on'] : $details['starts_on'];
        if ($details['starts_on'] < $first || $details['starts_on'] > $last) {
            $errors['starts_on'] = "Choose a date in the class's academic year ({$period->name}).";
        } elseif ($ends !== null && $ends > $last) {
            $errors['ends_on'] = "The schedule of this class ends with its academic year ({$period->name}).";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{title: string, location: ?string, starts_on: string, repeats_weekly: bool, ends_on: ?string, start_time: string, end_time: string, notes: ?string}  $details
     * @return array<string, mixed>
     */
    private function attributes(array $details): array
    {
        return [
            'title' => $details['title'],
            'location' => $details['location'],
            'starts_on' => $details['starts_on'],
            'repeats_weekly' => $details['repeats_weekly'],
            'ends_on' => $details['repeats_weekly'] ? $details['ends_on'] : null,
            'start_time' => $details['start_time'],
            'end_time' => $details['end_time'],
            'notes' => $details['notes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ScheduleEntry $entry): array
    {
        return [
            'title' => $entry->title,
            'subject' => $entry->class_subject_id === null ? null : ClassSubject::query()->whereKey($entry->class_subject_id)->with('subject:id,name')->first()?->subject->name,
            'instructor' => $entry->instructor_id === null ? null : User::query()->whereKey($entry->instructor_id)->value('name'),
            'location' => $entry->location,
            'starts_on' => $entry->starts_on->toDateString(),
            'repeats_weekly' => $entry->repeats_weekly,
            'ends_on' => $entry->ends_on?->toDateString(),
            'start_time' => $entry->startLabel(),
            'end_time' => $entry->endLabel(),
            'notes' => $entry->notes,
        ];
    }
}
