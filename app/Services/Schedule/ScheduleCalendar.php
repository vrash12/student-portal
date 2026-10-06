<?php

namespace App\Services\Schedule;

use App\Enums\ExaminationStatus;
use App\Models\AttendanceSession;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\FitnessTest;
use App\Models\InstructorAssignment;
use App\Models\ScheduleEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The calendar of one or more classes, or of one instructor, over a range of
 * days (owner request, 2026-10-06): schedule entries (weekly ones expanded
 * to each date) together with the examinations that open, the fitness tests
 * and, for staff, the attendance sessions on those days. Nothing is copied:
 * every item is read from its own table. Dates and times are the
 * institution's local ones.
 *
 * Items: kind (session | examination | fitness | attendance), id (of the
 * entry, examination, test or session), title, date (Y-m-d), start and end
 * ("HH:MM", null for all-day items), subject, instructor, location,
 * classBatchId, className, notes, repeatsWeekly, closesAt (examinations,
 * ISO). Pages add their own links.
 */
final class ScheduleCalendar
{
    /**
     * @param  list<int>  $classIds
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    public function forClasses(array $classIds, CarbonImmutable $from, CarbonImmutable $to, bool $withFitness, bool $withAttendance): array
    {
        [$from, $to] = [self::day($from), self::day($to)];
        if ($classIds === []) {
            return $this->days($from, $to, collect());
        }

        $entries = $this->entries($from, $to, fn (Builder $query) => $query->whereIn('class_batch_id', $classIds));
        $exams = $this->examinations($from, $to, fn (Builder $query) => $query->whereIn('class_subject_id', ClassSubject::query()->select('id')->whereIn('class_batch_id', $classIds)));
        $fitness = $withFitness ? $this->fitnessTests($from, $to, $classIds) : collect();
        $attendance = $withAttendance ? $this->attendanceSessions($from, $to, $classIds) : collect();

        return $this->days($from, $to, $entries->concat($exams)->concat($fitness)->concat($attendance));
    }

    /**
     * An instructor's own calendar: entries naming them or a subject they
     * teach, and the examinations of the subjects they teach.
     *
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    public function forInstructor(User $instructor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$from, $to] = [self::day($from), self::day($to)];
        $taught = InstructorAssignment::query()->select('class_subject_id')->where('instructor_id', $instructor->id);

        $entries = $this->entries($from, $to, fn (Builder $query) => $query->where(
            fn (Builder $query) => $query->where('instructor_id', $instructor->id)->orWhereIn('class_subject_id', $taught),
        ));
        $exams = $this->examinations($from, $to, fn (Builder $query) => $query->whereIn('class_subject_id', $taught));

        return $this->days($from, $to, $entries->concat($exams));
    }

    /**
     * @param  callable(Builder<ScheduleEntry>): mixed  $constrain
     * @return Collection<int, array<string, mixed>>
     */
    private function entries(CarbonImmutable $from, CarbonImmutable $to, callable $constrain): Collection
    {
        $query = ScheduleEntry::query()
            ->where('starts_on', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('repeats_weekly', false)->where('starts_on', '>=', $from->toDateString()))
                ->orWhere(fn (Builder $query) => $query->where('repeats_weekly', true)->where('ends_on', '>=', $from->toDateString())))
            ->with(['classBatch:id,name', 'classSubject.subject:id,name', 'instructor:id,name']);
        $constrain($query);

        return $query->get()->flatMap(fn (ScheduleEntry $entry): array => array_map(fn (CarbonImmutable $date): array => [
            'kind' => 'session',
            'id' => $entry->id,
            'title' => $entry->title,
            'date' => $date->toDateString(),
            'start' => $entry->startLabel(),
            'end' => $entry->endLabel(),
            'subject' => $entry->classSubject?->subject->name,
            'instructor' => $entry->instructor?->name,
            'location' => $entry->location,
            'classBatchId' => $entry->class_batch_id,
            'className' => $entry->classBatch->name,
            'notes' => $entry->notes,
            'repeatsWeekly' => $entry->repeats_weekly,
            'closesAt' => null,
        ], $entry->occurrencesBetween($from, $to)));
    }

    /**
     * Published examinations opening in the range, on the day they open.
     *
     * @param  callable(Builder<Examination>): mixed  $constrain
     * @return Collection<int, array<string, mixed>>
     */
    private function examinations(CarbonImmutable $from, CarbonImmutable $to, callable $constrain): Collection
    {
        $timezone = $this->timezone();
        $query = Examination::query()
            ->select(['id', 'class_subject_id', 'title', 'kind', 'opens_at', 'closes_at'])
            ->where('status', ExaminationStatus::Published)
            ->whereNotNull('opens_at')
            // The range's days in the institution's timezone; opens_at is stored in UTC.
            ->whereBetween('opens_at', [
                CarbonImmutable::parse($from->toDateString().' 00:00:00', $timezone)->utc(),
                CarbonImmutable::parse($to->toDateString().' 23:59:59', $timezone)->utc(),
            ])
            ->with(['classSubject:id,class_batch_id,subject_id', 'classSubject.subject:id,name', 'classSubject.classBatch:id,name']);
        $constrain($query);

        return $query->get()->map(function (Examination $exam) use ($timezone): array {
            $opens = CarbonImmutable::instance($exam->opens_at)->timezone($timezone);
            $closes = $exam->closes_at === null ? null : CarbonImmutable::instance($exam->closes_at)->timezone($timezone);

            return [
                'kind' => 'examination',
                'id' => $exam->id,
                'title' => $exam->title,
                'date' => $opens->toDateString(),
                'start' => $opens->format('H:i'),
                'end' => $closes !== null && $closes->isSameDay($opens) ? $closes->format('H:i') : null,
                'subject' => $exam->classSubject->subject->name,
                'instructor' => null,
                'location' => null,
                'classBatchId' => $exam->classSubject->class_batch_id,
                'className' => $exam->classSubject->classBatch->name,
                'notes' => null,
                'repeatsWeekly' => false,
                'closesAt' => $closes?->toIso8601String(),
            ];
        });
    }

    /**
     * @param  list<int>  $classIds
     * @return Collection<int, array<string, mixed>>
     */
    private function fitnessTests(CarbonImmutable $from, CarbonImmutable $to, array $classIds): Collection
    {
        return FitnessTest::query()
            ->whereIn('class_batch_id', $classIds)
            ->whereBetween('tested_on', [$from->toDateString(), $to->toDateString()])
            ->with('classBatch:id,name')
            ->get()
            ->map(fn (FitnessTest $test): array => $this->allDay('fitness', $test->id, $test->title, $test->tested_on->toDateString(), $test->class_batch_id, $test->classBatch->name));
    }

    /**
     * @param  list<int>  $classIds
     * @return Collection<int, array<string, mixed>>
     */
    private function attendanceSessions(CarbonImmutable $from, CarbonImmutable $to, array $classIds): Collection
    {
        return AttendanceSession::query()
            ->whereIn('class_batch_id', $classIds)
            ->whereBetween('held_on', [$from->toDateString(), $to->toDateString()])
            ->with('classBatch:id,name')
            ->get()
            ->map(fn (AttendanceSession $session): array => $this->allDay('attendance', $session->id, $session->title, $session->held_on->toDateString(), $session->class_batch_id, $session->classBatch->name));
    }

    /**
     * @return array<string, mixed>
     */
    private function allDay(string $kind, int $id, string $title, string $date, int $classBatchId, string $className): array
    {
        return [
            'kind' => $kind, 'id' => $id, 'title' => $title, 'date' => $date, 'start' => null, 'end' => null,
            'subject' => null, 'instructor' => null, 'location' => null, 'classBatchId' => $classBatchId,
            'className' => $className, 'notes' => null, 'repeatsWeekly' => false, 'closesAt' => null,
        ];
    }

    /**
     * Every day of the range, with its items: all-day items first, then by
     * start time and title.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    private function days(CarbonImmutable $from, CarbonImmutable $to, Collection $items): array
    {
        $byDate = $items->groupBy('date');
        $days = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'items' => ($byDate->get($date->toDateString()) ?? collect())
                    ->sortBy([fn (array $a, array $b): int => [$a['start'] !== null, $a['start'] ?? '', $a['title']] <=> [$b['start'] !== null, $b['start'] ?? '', $b['title']]])
                    ->values()
                    ->all(),
            ];
        }

        return $days;
    }

    /**
     * The calendar date alone (midnight in the application's timezone, as
     * date columns are read), so dates of any timezone compare by day.
     */
    private static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString(), (string) config('app.timezone'));
    }

    private function timezone(): string
    {
        return (string) config('institution.timezone');
    }
}
