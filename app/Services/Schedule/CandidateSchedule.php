<?php

namespace App\Services\Schedule;

use App\Models\Candidate;
use Carbon\CarbonImmutable;

/**
 * The candidate's own schedule (owner request, 2026-10-06): their class's
 * schedule entries and published examinations, and fitness tests only when
 * the portal shows fitness. Attendance sessions, notes of other classes and
 * anything of another class are never included. A candidate without an
 * eligible class has an empty schedule.
 */
final class CandidateSchedule
{
    public function __construct(private readonly ScheduleCalendar $calendar) {}

    /**
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    public function days(Candidate $candidate, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $classId = $candidate->class_batch_id !== null && $candidate->isGradableIn($candidate->class_batch_id)
            ? (int) $candidate->class_batch_id
            : null;

        $days = $classId === null
            ? []
            : $this->calendar->forClasses([$classId], $from, $to, withFitness: (bool) config('institution.portal.show_fitness'), withAttendance: false);

        if ($classId === null) {
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $days[] = ['date' => $date->toDateString(), 'items' => []];
            }
        }

        return array_map(fn (array $day): array => [
            'date' => $day['date'],
            'items' => array_map(fn (array $item): array => [
                'kind' => $item['kind'],
                'key' => "{$item['kind']}-{$item['id']}-{$day['date']}",
                'title' => $item['title'],
                'start' => $item['start'],
                'end' => $item['end'],
                'subject' => $item['subject'],
                'instructor' => $item['instructor'],
                'location' => $item['location'],
                'notes' => $item['notes'],
                'closesAt' => $item['closesAt'],
                'href' => $item['kind'] === 'examination' ? route('portal.examinations.show', $item['id'], false) : null,
            ], $day['items']),
        ], $days);
    }

    /**
     * Today and tomorrow, for the portal home page.
     *
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    public function todayAndTomorrow(Candidate $candidate): array
    {
        $today = ScheduleWeek::today();

        return $this->days($candidate, $today, $today->addDay());
    }
}
