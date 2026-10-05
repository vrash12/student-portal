<?php

namespace App\Services\Nutrition;

use App\Enums\CandidateStatus;
use App\Enums\NutritionStatus;
use App\Models\Candidate;
use App\Models\NutritionAssessment;
use App\Models\NutritionStandards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The nutrition state of many candidates at once, for the Nutrition list and
 * the dashboard: each candidate's latest assessment (BMI category, waist
 * risk, weight change since the one before, next review) and whether they
 * need the dietitian's attention. Only candidates in training (enrolled or
 * on leave) are listed: withdrawn and completed ones are left out.
 *
 * A candidate needs attention when never assessed, when the BMI is not in
 * the normal range, when the waist-to-height ratio reaches the risk line,
 * or when the next review date has come.
 */
final class NutritionMonitoring
{
    private readonly NutritionStandards $standards;

    public function __construct(?NutritionStandards $standards = null)
    {
        $this->standards = $standards ?? NutritionStandards::current();
    }

    public function standards(): NutritionStandards
    {
        return $this->standards;
    }

    /**
     * @param  Builder<Candidate>  $candidates  already limited to the viewer's campus
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Builder $candidates, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();
        $list = $candidates
            ->whereIn('status', [CandidateStatus::Enrolled->value, CandidateStatus::OnLeave->value])
            ->with('classBatch:id,name')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get();

        $assessments = NutritionAssessment::query()
            ->whereIn('candidate_id', $list->modelKeys())
            ->newestFirst()
            ->get(['id', 'candidate_id', 'assessed_on', 'height_cm', 'weight_kg', 'waist_cm', 'next_review_on'])
            ->groupBy('candidate_id');

        return $list->map(fn (Candidate $candidate): array => $this->row($candidate, $assessments->get($candidate->id, collect()), $today))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{total: int, assessed: int, notAssessed: int, needsAttention: int, reviewDue: int, waistAtRisk: int, byStatus: array<string, int>}
     */
    public function counts(Collection $rows): array
    {
        $byStatus = [];
        foreach (NutritionStatus::cases() as $status) {
            $byStatus[$status->value] = $rows->filter(fn (array $row): bool => ($row['status']['value'] ?? null) === $status->value)->count();
        }

        return [
            'total' => $rows->count(),
            'assessed' => $rows->whereNotNull('assessedOn')->count(),
            'notAssessed' => $rows->whereNull('assessedOn')->count(),
            'needsAttention' => $rows->where('needsAttention', true)->count(),
            'reviewDue' => $rows->where('reviewDue', true)->count(),
            'waistAtRisk' => $rows->where('waistAtRisk', true)->count(),
            'byStatus' => $byStatus,
        ];
    }

    /**
     * @param  Collection<int, NutritionAssessment>  $history  newest first
     * @return array<string, mixed>
     */
    private function row(Candidate $candidate, Collection $history, CarbonImmutable $today): array
    {
        $latest = $history->first();
        $previous = $history->get(1);
        $bmi = $latest?->bmi();
        $status = $bmi === null ? null : $this->standards->classify($bmi);
        $waistToHeight = $latest?->waistToHeight();
        $waistAtRisk = $waistToHeight !== null && $this->standards->waistAtRisk($waistToHeight);
        $reviewDue = $latest?->next_review_on !== null && $latest->next_review_on->lessThanOrEqualTo($today);

        return [
            'id' => $candidate->id,
            'number' => $candidate->candidate_number,
            'name' => $candidate->full_name,
            'classId' => $candidate->class_batch_id,
            'className' => $candidate->classBatch?->name,
            'assessments' => $history->count(),
            'assessedOn' => $latest?->assessed_on->toDateString(),
            'weightKg' => $latest === null ? null : (float) $latest->weight_kg,
            'bmi' => $bmi,
            'status' => $status?->toArray(),
            'waistToHeight' => $waistToHeight,
            'waistAtRisk' => $waistAtRisk,
            // Since the assessment before (kg; null with one assessment or none).
            'weightChange' => $previous === null ? null : round((float) $latest->weight_kg - (float) $previous->weight_kg, 1),
            'nextReviewOn' => $latest?->next_review_on?->toDateString(),
            'reviewDue' => $reviewDue,
            'needsAttention' => $latest === null || $status !== NutritionStatus::Normal || $waistAtRisk || $reviewDue,
        ];
    }
}
