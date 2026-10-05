<?php

namespace Database\Seeders;

use App\Enums\ActivityLevel;
use App\Enums\CampusCode;
use App\Enums\CandidateStatus;
use App\Enums\NutritionGoal;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\CandidateDietaryProfile;
use App\Models\CandidateMedicalValue;
use App\Models\NutritionAssessment;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo nutrition monitoring (owner request, 2026-10-05): one fictional
 * dietitian per campus and two synthetic assessments for each candidate
 * (about six weeks apart), with a few dietary profiles. Measurements are
 * derived from the candidate number so every run gives the same figures;
 * they describe no real person. A few candidates are left unassessed and a
 * few have their review due, so the monitoring list has something to show.
 *
 * Safe to run again: dietitians are updated in place, and candidates who
 * already have an assessment are left alone. Never runs in production.
 *
 * php artisan db:seed --class=DemoNutritionSeeder
 */
class DemoNutritionSeeder extends Seeder
{
    /** Campus => [username, name]. */
    public const DIETITIANS = [
        'SOUTH' => ['dietitian1', 'Maricel A. Bautista'],
        'NORTH' => ['dietitian2', 'Jonathan P. Villanueva'],
        'EAST' => ['dietitian3', 'Grace L. Mendoza'],
        'WEST' => ['dietitian4', 'Ramon T. Agustin'],
    ];

    private const ALLERGIES = [null, null, null, 'Shrimp and crab', null, 'Peanuts', null, null];

    private const RESTRICTIONS = [null, null, 'No pork', null, null, null, 'Lactose intolerant', null];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo nutrition data must not be seeded in production.');
        }

        $role = Role::query()->where('code', SystemRole::Dietitian->value)->firstOrFail();
        foreach (self::DIETITIANS as $code => [$username, $name]) {
            $campus = DemoCampusSeeder::campus(CampusCode::from($code));
            $dietitian = User::query()->where('username', $username)->first() ?? new User(['username' => $username, 'email' => null]);
            $dietitian->fill(['name' => $name, 'password' => ClientDemoSeeder::PASSWORD]);
            $dietitian->role()->associate($role);
            $dietitian->campus()->associate($campus);
            $dietitian->is_active = true;
            $dietitian->password_change_required = false;
            $dietitian->save();

            $candidates = Candidate::query()
                ->where('campus_id', $campus->id)
                ->whereIn('status', [CandidateStatus::Enrolled->value, CandidateStatus::OnLeave->value])
                ->whereDoesntHave('nutritionAssessments')
                ->orderBy('candidate_number')
                ->get();

            foreach ($candidates as $index => $candidate) {
                $this->seedCandidate($candidate, $dietitian, $index);
            }
        }
    }

    private function seedCandidate(Candidate $candidate, User $dietitian, int $index): void
    {
        $seed = crc32($candidate->candidate_number);
        // Every seventh candidate is not assessed yet.
        if ($index % 7 === 6) {
            return;
        }

        // Start from the height and weight of the demo medical record when it has them,
        // so both records agree; otherwise from a BMI of about 18 to 29, mostly normal.
        [$height, $firstWeight] = $this->medicalHeightAndWeight($candidate)
            ?? (function () use ($seed): array {
                $height = 158 + ($seed % 25);
                $bmi = [21.5, 22.4, 20.1, 24.6, 22.9, 17.9, 26.3, 21.0, 28.4, 22.0][$seed % 10];

                return [$height, round($bmi * ($height / 100) ** 2, 1)];
            })();
        // Weight change over the six weeks of training between the two assessments.
        $weight = round($firstWeight + [-1.5, 0.8, 2.6, -0.6, 3.4, 0.2, 5.8, -2.4][$seed % 8], 1);
        $bmi = $weight / ($height / 100) ** 2;
        $today = CarbonImmutable::today();
        $latestOn = $today->subDays(5 + ($seed % 30));
        $firstOn = $latestOn->subDays(42);
        $goal = $bmi < 18.5 ? NutritionGoal::Gain : ($bmi >= 23 ? NutritionGoal::Lose : NutritionGoal::Maintain);

        foreach ([[$firstOn, $firstWeight], [$latestOn, $weight]] as [$date, $kg]) {
            $assessment = new NutritionAssessment([
                'assessed_on' => $date->toDateString(),
                'height_cm' => $height,
                'weight_kg' => round($kg, 1),
                'waist_cm' => round($height * (0.40 + ($bmi - 18) * 0.012), 1),
                'activity_level' => ActivityLevel::Heavy->value,
                'meals_per_day' => 3 + ($seed % 2),
                'diet_history' => 'Mess hall breakfast, lunch and dinner; rice with each meal; coffee in the morning; snacks after field training.',
                'clinical_findings' => $goal === NutritionGoal::Gain ? 'Reports tiredness after runs.' : null,
                'diagnosis' => match ($goal) {
                    NutritionGoal::Gain => 'Energy intake below needs during field training, shown by a BMI below the normal range.',
                    NutritionGoal::Lose => 'Energy intake above needs, shown by a BMI above the normal range.',
                    NutritionGoal::Maintain => null,
                },
                'goal' => $goal->value,
                'target_weight_kg' => $goal === NutritionGoal::Maintain ? null : round(22 * ($height / 100) ** 2, 1),
                'energy_target_kcal' => match ($goal) {
                    NutritionGoal::Gain => 3600,
                    NutritionGoal::Lose => 2600,
                    NutritionGoal::Maintain => 3000,
                },
                'plan' => match ($goal) {
                    NutritionGoal::Gain => 'Add a snack after training (bread with peanut butter or a banana and milk). Finish every meal. Drink water through the day.',
                    NutritionGoal::Lose => 'One cup of rice per meal. More vegetables. Water instead of sweet drinks. No late-night snacks.',
                    NutritionGoal::Maintain => 'Keep the current meals. Drink at least 2 litres of water on training days.',
                },
                // Every fifth candidate's review has come.
                'next_review_on' => ($index % 5 === 2 ? $today->subDays(1) : $date->addDays(30))->toDateString(),
            ]);
            $assessment->candidate()->associate($candidate);
            $assessment->assessor()->associate($dietitian);
            $assessment->save();
        }

        $this->dietaryProfile($candidate, $dietitian, $seed);
    }

    /**
     * The candidate's height (cm) and weight (kg) from the demo medical record, or null.
     *
     * @return array{0: float, 1: float}|null
     */
    private function medicalHeightAndWeight(Candidate $candidate): ?array
    {
        $values = CandidateMedicalValue::query()
            ->where('candidate_id', $candidate->id)
            ->whereHas('field', fn ($fields) => $fields->whereIn('name', ['Height', 'Weight']))
            ->with('field:id,name')
            ->get()
            ->mapWithKeys(fn (CandidateMedicalValue $value): array => [$value->field->name => $value->value]);
        $height = (float) ($values['Height'] ?? 0);
        $weight = (float) ($values['Weight'] ?? 0);

        return $height >= 100 && $height <= 250 && $weight >= 25 && $weight <= 300 ? [$height, $weight] : null;
    }

    private function dietaryProfile(Candidate $candidate, User $dietitian, int $seed): void
    {
        $allergy = self::ALLERGIES[$seed % count(self::ALLERGIES)];
        $restriction = self::RESTRICTIONS[($seed >> 3) % count(self::RESTRICTIONS)];
        if ($allergy !== null || $restriction !== null) {
            $profile = CandidateDietaryProfile::query()->where('candidate_id', $candidate->id)->first()
                ?? (new CandidateDietaryProfile)->forceFill(['candidate_id' => $candidate->id]);
            $profile->fill(['food_allergies' => $allergy, 'dietary_restrictions' => $restriction, 'supplements' => null]);
            $profile->forceFill(['updated_by' => $dietitian->id])->save();
        }
    }
}
