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
     * Classes grouped by academic period, active period first.
     *
     * @return list<array{period: string, isActive: bool, classes: list<array{id: int, name: string}>}>
     */
    public static function classBatchesByPeriod(): array
    {
        return AcademicPeriod::query()
            ->with(['classBatches' => fn ($query) => $query->orderBy('name')])
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->get()
            ->filter(fn (AcademicPeriod $period): bool => $period->classBatches->isNotEmpty())
            ->map(fn (AcademicPeriod $period): array => [
                'period' => $period->name,
                'isActive' => $period->is_active,
                'classes' => $period->classBatches
                    ->map(fn (ClassBatch $classBatch): array => ['id' => $classBatch->id, 'name' => $classBatch->name])
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
     * Active teaching staff who can receive assignments.
     *
     * @return list<array{id: int, name: string, username: string}>
     */
    public static function eligibleInstructors(): array
    {
        return User::query()
            ->eligibleToTeach()
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username])
            ->values()
            ->all();
    }
}
