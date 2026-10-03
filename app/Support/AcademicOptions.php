<?php

namespace App\Support;

use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use App\Models\User;

/**
 * Option lists for academic forms and filters.
 */
final class AcademicOptions
{
    /**
     * Classes grouped by academic period, active period first, limited to
     * the campus scope (CampusScope; every campus when null, for callers
     * outside a request such as seeders).
     *
     * @return list<array{period: string, isActive: bool, classes: list<array{id: int, name: string}>}>
     */
    public static function classBatchesByPeriod(?CampusScope $campus = null): array
    {
        $campus ??= CampusScope::everyCampus();
        $labelsCampuses = $campus->labelsCampuses();

        return AcademicPeriod::query()
            ->with(['classBatches' => fn ($query) => $campus->constrain($query, 'class_batches.campus_id')->with('campus:id,code')->orderBy('name')])
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->get()
            ->filter(fn (AcademicPeriod $period): bool => $period->classBatches->isNotEmpty())
            ->map(fn (AcademicPeriod $period): array => [
                'period' => $period->name,
                'isActive' => $period->is_active,
                'classes' => $period->classBatches
                    ->map(fn (ClassBatch $classBatch): array => ['id' => $classBatch->id, 'name' => $campus->classLabel($classBatch->name, $classBatch->campus?->code, $labelsCampuses)])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, startsOn: string, isActive: bool}>
     */
    public static function academicPeriods(): array
    {
        return AcademicPeriod::query()
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                'id' => $period->id,
                'name' => $period->name,
                'startsOn' => $period->starts_on->toDateString(),
                'isActive' => $period->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * Active teaching staff who can receive assignments, limited to one
     * campus (instructors only teach on their own campus) or to the campus
     * scope when no campus is given.
     *
     * @return list<array{id: int, name: string, username: string}>
     */
    public static function eligibleInstructors(?int $campusId = null, ?CampusScope $campus = null): array
    {
        return User::query()
            ->eligibleToTeach()
            ->when($campusId !== null, fn ($users) => $users->where('campus_id', $campusId))
            ->when($campusId === null && $campus !== null, fn ($users) => $campus->constrain($users, 'users.campus_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username])
            ->values()
            ->all();
    }
}
