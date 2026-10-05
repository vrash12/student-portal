<?php

namespace App\Services\Nutrition;

use App\Enums\AuditAction;
use App\Models\Candidate;
use App\Models\CandidateDietaryProfile;
use App\Models\NutritionAssessment;
use App\Models\NutritionStandards;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every change to nutrition records (owner request, 2026-10-05): a
 * dietitian's assessments and the dietary profile, and the standards set by
 * administrators. Monitoring only: nothing here changes grades,
 * qualification or class rank.
 *
 * Like medical values, measurements, findings and plans never go into the
 * audit log: an entry records who did what to whose record, and for a
 * correction only which fields changed.
 */
final class NutritionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated assessment fields
     */
    public function recordAssessment(Candidate $candidate, array $data, User $actor): NutritionAssessment
    {
        return DB::transaction(function () use ($candidate, $data, $actor): NutritionAssessment {
            $assessment = new NutritionAssessment($this->withReviewDate($data));
            $assessment->candidate()->associate($candidate);
            $assessment->assessor()->associate($actor);
            $assessment->save();

            $this->audit->record(AuditAction::NutritionAssessmentRecorded, $candidate, newValues: [
                'assessment_id' => $assessment->id,
                'assessed_on' => $assessment->assessed_on->toDateString(),
            ]);

            return $assessment;
        });
    }

    /**
     * Corrects an assessment. Returns the names of the fields that changed.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function correctAssessment(NutritionAssessment $assessment, array $data): array
    {
        return DB::transaction(function () use ($assessment, $data): array {
            $locked = NutritionAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            $locked->fill($this->withReviewDate($data));
            $changed = array_keys($locked->getDirty());
            if ($changed === []) {
                return [];
            }

            $locked->save();
            $assessment->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(AuditAction::NutritionAssessmentUpdated, $locked->candidate, newValues: [
                'assessment_id' => $locked->id,
                'assessed_on' => $locked->assessed_on->toDateString(),
                'fields_changed' => $changed,
            ]);

            return $changed;
        });
    }

    public function deleteAssessment(NutritionAssessment $assessment, string $reason): void
    {
        DB::transaction(function () use ($assessment, $reason): void {
            $candidate = $assessment->candidate;
            $snapshot = ['assessment_id' => $assessment->id, 'assessed_on' => $assessment->assessed_on->toDateString()];
            $assessment->delete();

            $this->audit->record(AuditAction::NutritionAssessmentDeleted, $candidate, oldValues: $snapshot, reason: $reason);
        });
    }

    /**
     * @param  array{food_allergies: ?string, dietary_restrictions: ?string, supplements: ?string}  $data
     * @return list<string> the fields that changed
     */
    public function saveDietaryProfile(Candidate $candidate, array $data, User $actor): array
    {
        return DB::transaction(function () use ($candidate, $data, $actor): array {
            // One profile per candidate: the unique key and the candidate row lock keep it so.
            Candidate::query()->whereKey($candidate->id)->lockForUpdate()->first();
            $profile = CandidateDietaryProfile::query()->where('candidate_id', $candidate->id)->first()
                ?? (new CandidateDietaryProfile)->forceFill(['candidate_id' => $candidate->id]);
            $profile->fill($data);
            // A field changed when its value differs from the stored one (none stored for a new profile).
            $changed = array_values(array_filter(CandidateDietaryProfile::FIELDS, fn (string $field): bool => $profile->getOriginal($field) !== $profile->getAttribute($field)));
            if ($changed === []) {
                return [];
            }

            $profile->forceFill(['updated_by' => $actor->id])->save();

            $this->audit->record(AuditAction::DietaryProfileUpdated, $candidate, newValues: ['fields_changed' => $changed]);

            return $changed;
        });
    }

    /**
     * @param  array{underweight_below: float, overweight_from: float, obese_from: float, waist_to_height_risk: float, review_interval_days: int}  $data
     */
    public function updateStandards(array $data, User $actor): NutritionStandards
    {
        return DB::transaction(function () use ($data, $actor): NutritionStandards {
            $standards = NutritionStandards::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $before = $standards->toSummary();
            $standards->fill($data);
            $standards->forceFill(['updated_by' => $actor->id])->save();

            $this->audit->recordChanges(AuditAction::NutritionStandardsUpdated, $standards, $before, $standards->toSummary());

            return $standards;
        });
    }

    /**
     * Without a next review date, the next review is the standards' interval
     * after the assessment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withReviewDate(array $data): array
    {
        if (($data['next_review_on'] ?? null) === null && isset($data['assessed_on'])) {
            $data['next_review_on'] = CarbonImmutable::parse((string) $data['assessed_on'])
                ->addDays(NutritionStandards::current()->review_interval_days)
                ->toDateString();
        }

        return $data;
    }
}
