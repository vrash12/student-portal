<?php

namespace Database\Seeders;

use App\Enums\AnnouncementAudience;
use App\Models\AcademicPeriod;
use App\Models\Announcement;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\Announcements\AnnouncementService;
use App\Services\Schedule\ScheduleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo training schedules and notices (owner request, 2026-10-06): a weekly
 * timetable for every class of the active academic year (physical training
 * each weekday morning, a Monday formation, two lecture slots per subject
 * with its instructor and a room, Friday field training) and three notices
 * (to every candidate, to the South Campus and to Class A). Synthetic
 * content only.
 *
 * Safe to run again: classes that already have a schedule, and notices with
 * the same title, are left alone. Never runs in production.
 *
 * php artisan db:seed --class=DemoScheduleSeeder
 */
class DemoScheduleSeeder extends Seeder
{
    /** Lecture slots: weekday (1 = Monday) and times, two per subject in turn. */
    private const LECTURE_SLOTS = [
        [1, '08:00', '10:00'], [3, '08:00', '10:00'],
        [2, '08:00', '10:00'], [4, '08:00', '10:00'],
        [1, '10:30', '12:00'], [3, '10:30', '12:00'],
        [2, '10:30', '12:00'], [4, '10:30', '12:00'],
    ];

    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly AnnouncementService $announcements,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo schedules must not be seeded in production.');
        }

        $period = AcademicPeriod::query()->active()->first();
        $admin = User::query()->where('username', 'admin')->first();
        if ($period === null || $admin === null) {
            return;
        }

        $classes = ClassBatch::query()->where('academic_period_id', $period->id)->orderBy('id')->get();
        foreach ($classes as $class) {
            if (! ScheduleEntry::query()->where('class_batch_id', $class->id)->exists()) {
                $this->timetable($class, $period, $admin);
            }
        }

        $this->notices($classes->firstWhere('name', 'Class A'), $admin);
    }

    private function timetable(ClassBatch $class, AcademicPeriod $period, User $admin): void
    {
        $firstMonday = $period->starts_on->startOfWeek(CarbonInterface::MONDAY);
        $add = function (int $weekday, string $start, string $end, string $title, ?string $location, ?ClassSubject $offering = null, ?string $notes = null) use ($class, $period, $admin, $firstMonday): void {
            $first = $firstMonday->addDays($weekday - 1);
            if ($first->lt($period->starts_on)) {
                $first = $first->addWeek();
            }
            $this->schedule->create($class, [
                'class_subject_id' => $offering?->id,
                'instructor_id' => $offering?->instructorAssignments->first()?->instructor_id,
                'title' => $title,
                'location' => $location,
                'starts_on' => $first->toDateString(),
                'repeats_weekly' => true,
                'ends_on' => $period->ends_on->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'notes' => $notes,
            ], $admin);
        };

        for ($weekday = 1; $weekday <= 5; $weekday++) {
            $add($weekday, '05:30', '06:30', 'Physical Training', 'Parade Ground', null, 'PT uniform and running shoes.');
        }
        $add(1, '07:00', '07:30', 'Morning Formation', 'Parade Ground');

        $offerings = ClassSubject::query()->where('class_batch_id', $class->id)->with(['subject:id,name', 'instructorAssignments'])->orderBy('id')->get();
        foreach ($offerings->values() as $index => $offering) {
            foreach ([self::LECTURE_SLOTS[(2 * $index) % 8], self::LECTURE_SLOTS[(2 * $index + 1) % 8]] as [$weekday, $start, $end]) {
                $add($weekday, $start, $end, "{$offering->subject->name} Lecture", 'Room '.($index + 1), $offering);
            }
        }

        $add(5, '13:00', '16:00', 'Field Training', 'Training Field', null, 'Field uniform and canteen.');
    }

    private function notices(?ClassBatch $classA, User $admin): void
    {
        $now = CarbonImmutable::now();
        $notices = [
            [AnnouncementAudience::Everyone, null, null, 'Welcome to the candidate portal', "Your notices, schedule, examinations and records are all here.\nCheck this page every morning before formation.", false, $admin],
            [AnnouncementAudience::Campus, $classA?->campus_id, null, 'Annual medical check-up', "Report to the campus clinic by class next week.\nBring your ID and fast for 8 hours before blood tests.", true, $admin],
        ];
        $instructor = User::query()->where('username', 'instructor1')->first();
        if ($classA !== null && $instructor !== null) {
            $notices[] = [AnnouncementAudience::ClassBatch, null, $classA->id, 'Subject 1 review session', "A review session is held on Thursday after the lecture.\nBring your notes and questions.", false, $instructor];
        }

        foreach ($notices as [$audience, $campusId, $classId, $title, $body, $important, $author]) {
            if ($audience === AnnouncementAudience::Campus && $campusId === null) {
                continue;
            }
            if (Announcement::query()->where('title', $title)->exists()) {
                continue;
            }
            $this->announcements->post($author, $audience, $campusId === null ? null : (int) $campusId, $classId, [
                'title' => $title, 'body' => $body, 'is_important' => $important, 'publishes_at' => $now, 'expires_at' => null,
            ]);
        }
    }
}
