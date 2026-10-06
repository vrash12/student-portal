<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleEntryRequest;
use App\Models\AttendanceSession;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\FitnessTest;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\Schedule\ScheduleCalendar;
use App\Services\Schedule\ScheduleScope;
use App\Services\Schedule\ScheduleService;
use App\Services\Schedule\ScheduleWeek;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The training schedule for staff (owner request, 2026-10-06): the week of a
 * class, or an instructor's own week ("My Schedule"), with the examinations,
 * fitness tests and attendance sessions of those days; and adding, changing
 * and removing schedule entries (schedule.manage). Classes come from
 * ScheduleScope, so another class's schedule is never shown or changed.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleCalendar $calendar,
        private readonly ScheduleService $schedule,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $scope = ScheduleScope::viewing($user)->filteredBy($request);
        $week = ScheduleWeek::containing($request->query('week'));
        $teaches = $user->canTeach();
        $view = $teaches && $request->query('view') !== 'class' ? 'mine' : 'class';

        $periods = $scope->periods();
        $periodIds = array_column($periods, 'id');
        $requestedPeriod = (int) QueryFilters::id($request, 'period');
        $periodId = in_array($requestedPeriod, $periodIds, true) ? $requestedPeriod : ($periodIds[0] ?? 0);
        $labels = $scope->campus->classLabels($periodId);
        $classes = $scope->classes()->where('academic_period_id', $periodId)->orderBy('name')->get(['id', 'name']);
        $requestedClass = (int) QueryFilters::id($request, 'class');
        $classId = $classes->contains('id', $requestedClass) ? $requestedClass : ($classes->first()?->id);

        $days = match (true) {
            $view === 'mine' => $this->calendar->forInstructor($user, $week->start, $week->end),
            $classId !== null => $this->calendar->forClasses(
                [$classId],
                $week->start,
                $week->end,
                withFitness: $user->hasPermission(Permission::ViewFitness),
                withAttendance: $user->hasPermission(Permission::ManageAttendance),
            ),
            default => $week->emptyDays(),
        };

        return Inertia::render('staff/schedule/index', [
            'week' => $week->toArray(),
            'days' => $this->withLinks($days, $user),
            'view' => $view,
            'filters' => [
                'period' => $periodId === 0 ? '' : (string) $periodId,
                'campus' => $scope->campus->filterValue(),
                'class' => $classId === null ? '' : (string) $classId,
                'week' => $week->start->toDateString(),
            ],
            'campusOptions' => $scope->campus->filterOptions(),
            'periods' => $periods,
            'classes' => $classes->map(fn (ClassBatch $class): array => ['id' => $class->id, 'name' => $labels[$class->id] ?? $class->name])->values()->all(),
            'can' => [
                'teach' => $teaches,
                'create' => $user->hasPermission(Permission::ManageSchedule) && ScheduleScope::managing($user)->classes()->exists(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $requestedClass = (int) QueryFilters::id($request, 'class');
        $requestedDate = ScheduleWeek::validDate($request->query('date'));

        return Inertia::render('staff/schedule/create', [
            ...$this->formOptions($user),
            'selectedClassId' => ScheduleScope::canManage($user, $requestedClass) ? $requestedClass : null,
            'date' => $requestedDate ?? ScheduleWeek::today()->toDateString(),
        ]);
    }

    public function store(ScheduleEntryRequest $request): RedirectResponse
    {
        $classBatch = ClassBatch::query()->findOrFail((int) $request->validated('class_batch_id'));
        Gate::authorize('create', [ScheduleEntry::class, $classBatch]);

        $entry = $this->schedule->create($classBatch, $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$entry->title} added to the schedule of {$classBatch->name}."]);

        return redirect()->route('schedule.index', ['view' => 'class', 'class' => $classBatch->id, 'period' => $classBatch->academic_period_id, 'week' => $entry->starts_on->toDateString()]);
    }

    public function edit(Request $request, ScheduleEntry $scheduleEntry): Response
    {
        $scheduleEntry->load('classBatch:id,name,campus_id,academic_period_id');

        return Inertia::render('staff/schedule/edit', [
            ...$this->formOptions($request->user(), $scheduleEntry->classBatch),
            'entry' => [
                'id' => $scheduleEntry->id,
                'classBatch' => ['id' => $scheduleEntry->classBatch->id, 'name' => $scheduleEntry->classBatch->name],
                'classSubjectId' => $scheduleEntry->class_subject_id,
                'instructorId' => $scheduleEntry->instructor_id,
                'title' => $scheduleEntry->title,
                'location' => $scheduleEntry->location,
                'startsOn' => $scheduleEntry->starts_on->toDateString(),
                'repeatsWeekly' => $scheduleEntry->repeats_weekly,
                'endsOn' => $scheduleEntry->ends_on?->toDateString(),
                'startTime' => $scheduleEntry->startLabel(),
                'endTime' => $scheduleEntry->endLabel(),
                'notes' => $scheduleEntry->notes,
            ],
        ]);
    }

    public function update(ScheduleEntryRequest $request, ScheduleEntry $scheduleEntry): RedirectResponse
    {
        $entry = $this->schedule->update($scheduleEntry, $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Schedule entry saved.']);

        return redirect()->route('schedule.index', $this->classWeekQuery($entry));
    }

    public function destroy(Request $request, ScheduleEntry $scheduleEntry): RedirectResponse
    {
        $query = $this->classWeekQuery($scheduleEntry);
        $this->schedule->delete($scheduleEntry, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$scheduleEntry->title} removed from the schedule."]);

        return redirect()->route('schedule.index', $query);
    }

    /**
     * Classes the user may schedule (with their subjects and assigned
     * instructors) and the teaching staff of their campuses; with $only, just
     * that class (editing: the class never changes).
     *
     * @return array<string, mixed>
     */
    private function formOptions(User $user, ?ClassBatch $only = null): array
    {
        $scope = ScheduleScope::managing($user);
        $classes = $only !== null
            ? new EloquentCollection([$only])
            : $scope->classes()->orderBy('name')->get(['id', 'name', 'campus_id', 'academic_period_id']);
        $classes->loadMissing(['academicPeriod:id,name,starts_on,ends_on,is_active', 'campus:id,code']);
        $labels = $scope->campus->labelsCampuses();

        $offerings = ClassSubject::query()
            ->whereIn('class_batch_id', $classes->modelKeys())
            ->with(['subject:id,name', 'instructorAssignments:id,class_subject_id,instructor_id'])
            ->get()
            ->groupBy('class_batch_id');

        $instructors = User::query()
            ->whereIn('campus_id', $classes->pluck('campus_id')->unique()->values()->all())
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($query) => $query->where('code', Permission::TeachClasses->value))
            ->orderBy('name')
            ->get(['id', 'name', 'campus_id']);

        return [
            'classOptions' => $classes
                ->sortBy([fn (ClassBatch $a, ClassBatch $b): int => [! $a->academicPeriod->is_active, $a->name] <=> [! $b->academicPeriod->is_active, $b->name]])
                ->map(fn (ClassBatch $class): array => [
                    'id' => $class->id,
                    'name' => $scope->campus->classLabel($class->name, $class->campus?->code, $labels),
                    'campusId' => (int) $class->campus_id,
                    'period' => [
                        'name' => $class->academicPeriod->name,
                        'isActive' => $class->academicPeriod->is_active,
                        'startsOn' => $class->academicPeriod->starts_on->toDateString(),
                        'endsOn' => $class->academicPeriod->ends_on->toDateString(),
                    ],
                    'subjects' => ($offerings->get($class->id) ?? collect())
                        ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
                        ->map(fn (ClassSubject $offering): array => [
                            'id' => $offering->id,
                            'name' => $offering->subject->name,
                            'instructorIds' => $offering->instructorAssignments->pluck('instructor_id')->map(fn ($id): int => (int) $id)->values()->all(),
                        ])->values()->all(),
                ])->values()->all(),
            'instructors' => $instructors->map(fn (User $instructor): array => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'campusId' => (int) $instructor->campus_id,
            ])->values()->all(),
        ];
    }

    /**
     * Links each item to its page when the user may open it.
     *
     * @param  list<array{date: string, items: list<array<string, mixed>>}>  $days
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    private function withLinks(array $days, User $user): array
    {
        $ids = ['session' => [], 'examination' => [], 'fitness' => [], 'attendance' => []];
        foreach ($days as $day) {
            foreach ($day['items'] as $item) {
                $ids[$item['kind']][] = $item['id'];
            }
        }

        $allowed = [
            'session' => ScheduleEntry::query()->whereKey(array_unique($ids['session']))->get()->filter(fn (ScheduleEntry $entry): bool => $user->can('manage', $entry))->modelKeys(),
            'examination' => Examination::query()->whereKey(array_unique($ids['examination']))->get()->filter(fn (Examination $exam): bool => $user->can('view', $exam))->modelKeys(),
            'fitness' => FitnessTest::query()->whereKey(array_unique($ids['fitness']))->get()->filter(fn (FitnessTest $test): bool => $user->can('view', $test))->modelKeys(),
            'attendance' => AttendanceSession::query()->whereKey(array_unique($ids['attendance']))->get()->filter(fn (AttendanceSession $session): bool => $user->can('manage', $session))->modelKeys(),
        ];

        return array_map(fn (array $day): array => [
            'date' => $day['date'],
            'items' => array_map(fn (array $item): array => [
                ...$item,
                'href' => in_array($item['id'], $allowed[$item['kind']], true) ? match ($item['kind']) {
                    'session' => route('schedule.entries.edit', $item['id'], false),
                    'examination' => route('examinations.show', $item['id'], false),
                    'fitness' => route('fitness.tests.show', $item['id'], false),
                    'attendance' => route('attendance.sessions.show', $item['id'], false),
                } : null,
            ], $day['items']),
        ], $days);
    }

    /**
     * @return array<string, string|int>
     */
    private function classWeekQuery(ScheduleEntry $entry): array
    {
        return [
            'view' => 'class',
            'class' => $entry->class_batch_id,
            'period' => (int) ClassBatch::query()->whereKey($entry->class_batch_id)->value('academic_period_id'),
            'week' => $entry->starts_on->toDateString(),
        ];
    }
}
