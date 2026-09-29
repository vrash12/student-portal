<?php

namespace App\Services\Grading;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The passing and warning grades of an academic period, which decide the
 * academic standing of every candidate in its classes.
 *
 * Rules enforced here (the Form Request only checks input shape):
 * - the warning grade is at least the passing grade;
 * - once thresholds are set and the period has finalized assessments, a
 *   change needs a reason, because it changes standings based on official
 *   grades.
 * The database CHECK constraint is the final guarantee of the value ranges.
 */
final class GradingThresholdService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function save(AcademicPeriod $period, string $passingGrade, string $warningGrade, ?string $reason): void
    {
        DB::transaction(function () use ($period, $passingGrade, $warningGrade, $reason): void {
            // Serializes concurrent edits so the audit entry's previous values
            // are the values actually replaced.
            $locked = AcademicPeriod::query()->lockForUpdate()->findOrFail($period->getKey());

            $before = $this->snapshot($locked);
            $after = [
                'passing_grade' => DecimalValue::normalize($passingGrade),
                'warning_grade' => DecimalValue::normalize($warningGrade),
            ];

            if (DecimalValue::toHundredths($warningGrade) < DecimalValue::toHundredths($passingGrade)) {
                throw ValidationException::withMessages([
                    'warning_grade' => "The warning grade must be equal to or higher than the passing grade ({$after['passing_grade']}).",
                ]);
            }

            if ($before === $after) {
                return;
            }

            $reason = $reason === null ? '' : trim($reason);
            $wasConfigured = $before['passing_grade'] !== null;
            if ($reason === '' && $wasConfigured && $this->hasFinalizedAssessments($locked)) {
                throw ValidationException::withMessages([
                    'reason' => 'Explain why the passing or warning grade is changing. Academic standings in this period will be recalculated.',
                ]);
            }

            $locked->forceFill($after)->save();

            $this->audit->record(
                AuditAction::GradingThresholdsUpdated,
                $locked,
                oldValues: $before,
                newValues: $after,
                reason: $reason === '' ? null : $reason,
            );
        });

        $period->refresh();
    }

    /**
     * Whether any class of the period has a finalized assessment, that is,
     * grades that already count.
     */
    public function hasFinalizedAssessments(AcademicPeriod $period): bool
    {
        return Assessment::query()
            ->finalized()
            ->whereHas('classSubject.classBatch', fn (Builder $classes) => $classes->where('academic_period_id', $period->id))
            ->exists();
    }

    /**
     * @return array{passing_grade: string|null, warning_grade: string|null}
     */
    private function snapshot(AcademicPeriod $period): array
    {
        return [
            'passing_grade' => DecimalValue::normalize($period->passing_grade),
            'warning_grade' => DecimalValue::normalize($period->warning_grade),
        ];
    }
}
