<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\User;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teaching staff and their subject/class assignments. Accounts themselves
 * are created and edited in the Users module.
 */
class InstructorController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $search = QueryFilters::search($request);
        $actor = $request->user();

        $instructors = User::query()
            ->teachingStaff()
            ->with('role.permissions')
            ->withCount('teachingAssignments')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search): void {
                $term = QueryFilters::likeTerm($search);
                $match->where('name', 'like', $term)->orWhere('username', 'like', $term);
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $instructor): array => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'username' => $instructor->username,
                'isActive' => $instructor->is_active,
                'assignmentCount' => (int) $instructor->teaching_assignments_count,
            ]);

        return Inertia::render('staff/instructors/index', [
            'instructors' => $instructors,
            'filters' => ['search' => $search],
            'canCreateAccounts' => $actor->can('create', User::class),
        ]);
    }

    public function show(Request $request, User $instructor): Response
    {
        abort_unless($instructor->isTeachingStaff(), 404);

        $assignments = $instructor->teachingAssignments()
            ->with(['classSubject.subject', 'classSubject.classBatch.academicPeriod'])
            ->get()
            ->sortBy(fn (InstructorAssignment $assignment): string => $assignment->classSubject->classBatch->name.' '.$assignment->classSubject->subject->name)
            ->map(fn (InstructorAssignment $assignment): array => [
                'id' => $assignment->id,
                'classBatch' => [
                    'id' => $assignment->classSubject->classBatch->id,
                    'name' => $assignment->classSubject->classBatch->name,
                    'period' => $assignment->classSubject->classBatch->academicPeriod->name,
                    'periodIsActive' => $assignment->classSubject->classBatch->academicPeriod->is_active,
                ],
                'subject' => [
                    'code' => $assignment->classSubject->subject->code,
                    'name' => $assignment->classSubject->subject->name,
                ],
            ])
            ->values()
            ->all();

        return Inertia::render('staff/instructors/show', [
            'instructor' => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'username' => $instructor->username,
                'isActive' => $instructor->is_active,
            ],
            'assignments' => $assignments,
            'offeringOptions' => $instructor->is_active ? $this->unassignedOfferings($instructor) : [],
            'canEditAccount' => $request->user()->can('update', $instructor),
        ]);
    }

    /**
     * Subjects of classes in the active academic period that this
     * instructor is not yet assigned to.
     *
     * @return list<array{id: int, label: string}>
     */
    private function unassignedOfferings(User $instructor): array
    {
        $activePeriodId = AcademicPeriod::query()->active()->value('id');

        if ($activePeriodId === null) {
            return [];
        }

        return ClassSubject::query()
            ->with(['classBatch', 'subject'])
            ->whereHas('classBatch', fn (Builder $classes) => $classes->where('academic_period_id', $activePeriodId))
            ->whereDoesntHave('instructorAssignments', fn (Builder $assignments) => $assignments->where('instructor_id', $instructor->id))
            ->get()
            ->sortBy(fn (ClassSubject $offering): string => $offering->classBatch->name.' '.$offering->subject->name)
            ->map(fn (ClassSubject $offering): array => [
                'id' => $offering->id,
                'label' => "{$offering->classBatch->name} · {$offering->subject->name}",
            ])
            ->values()
            ->all();
    }
}
