<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use App\Models\TrainingPhase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Training phases of the course (owner request, 2026-10-03), each within one
 * academic year (TrainingPhaseRequest checks the dates). Saving locks the
 * year's row, so phases of one year are added or changed one at a time.
 * Every change is audited. A phase that subjects of a class belong to cannot
 * be deleted: their phase averages are part of the academic record.
 */
final class TrainingPhaseService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{number: int, name: string, starts_on: string, ends_on: string}  $data
     */
    public function create(AcademicPeriod $period, array $data): TrainingPhase
    {
        return DB::transaction(function () use ($period, $data): TrainingPhase {
            $locked = AcademicPeriod::query()->lockForUpdate()->findOrFail($period->getKey());
            self::assertFits($locked, null, $data);
            $phase = new TrainingPhase($data);
            $phase->academicPeriod()->associate($locked);
            $phase->save();
            $this->audit->record(AuditAction::TrainingPhaseCreated, $phase, newValues: self::snapshot($phase));

            return $phase;
        });
    }

    /**
     * @param  array{number: int, name: string, starts_on: string, ends_on: string}  $data
     */
    public function update(TrainingPhase $phase, array $data): TrainingPhase
    {
        return DB::transaction(function () use ($phase, $data): TrainingPhase {
            $period = AcademicPeriod::query()->lockForUpdate()->findOrFail($phase->academic_period_id);
            $locked = TrainingPhase::query()->lockForUpdate()->findOrFail($phase->id);
            self::assertFits($period, $locked, $data);
            $before = self::snapshot($locked);
            $locked->fill($data)->save();
            $this->audit->recordChanges(AuditAction::TrainingPhaseUpdated, $locked, $before, self::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Why the phase's dates do not fit its academic year, as field => message:
     * they must lie inside the year and follow the phase numbers without
     * overlapping another phase of the year. Empty when they fit.
     *
     * @param  array{number: int, starts_on: string, ends_on: string}  $data
     * @return array<string, string>
     */
    public static function datesProblem(AcademicPeriod $period, ?TrainingPhase $except, array $data): array
    {
        $startsOn = CarbonImmutable::parse($data['starts_on']);
        $endsOn = CarbonImmutable::parse($data['ends_on']);
        $day = fn (CarbonImmutable $date): string => $date->format('M j, Y');

        if ($startsOn->lt($period->starts_on)) {
            return ['starts_on' => "The phase must start within {$period->name}, on or after {$day($period->starts_on)}."];
        }
        if ($endsOn->gt($period->ends_on)) {
            return ['ends_on' => "The phase must end within {$period->name}, on or before {$day($period->ends_on)}."];
        }

        $others = $period->trainingPhases()->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))->ordered()->get();
        foreach ($others as $other) {
            if ($other->number < $data['number'] && $startsOn->lte($other->ends_on)) {
                return ['starts_on' => "The phase must start after {$other->name} ends on {$day($other->ends_on)}."];
            }
            if ($other->number > $data['number'] && $endsOn->gte($other->starts_on)) {
                return ['ends_on' => "The phase must end before {$other->name} starts on {$day($other->starts_on)}."];
            }
        }

        return [];
    }

    /**
     * @param  array{number: int, starts_on: string, ends_on: string}  $data
     *
     * @throws ValidationException when the dates do not fit the year
     */
    private static function assertFits(AcademicPeriod $period, ?TrainingPhase $except, array $data): void
    {
        $problem = self::datesProblem($period, $except, $data);
        if ($problem !== []) {
            throw ValidationException::withMessages($problem);
        }
    }

    /**
     * @throws ValidationException when subjects of a class belong to the phase
     */
    public function delete(TrainingPhase $phase): void
    {
        DB::transaction(function () use ($phase): void {
            $locked = TrainingPhase::query()->lockForUpdate()->findOrFail($phase->id);
            $inUse = $locked->classSubjects()->count();
            if ($inUse > 0) {
                throw ValidationException::withMessages([
                    'phase' => "{$locked->name} is used by {$inUse} ".($inUse === 1 ? 'subject' : 'subjects').' of a class and cannot be deleted. Move them to another phase first.',
                ]);
            }

            $this->audit->record(AuditAction::TrainingPhaseDeleted, $locked, oldValues: self::snapshot($locked));
            $locked->delete();
        });
    }

    /**
     * @return array{academic_period_id: int, number: int, name: string, starts_on: string, ends_on: string}
     */
    private static function snapshot(TrainingPhase $phase): array
    {
        return [
            'academic_period_id' => (int) $phase->academic_period_id,
            'number' => $phase->number,
            'name' => $phase->name,
            'starts_on' => $phase->starts_on->toDateString(),
            'ends_on' => $phase->ends_on->toDateString(),
        ];
    }
}
