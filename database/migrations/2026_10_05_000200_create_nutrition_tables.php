<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nutrition monitoring by dietitians (owner request, 2026-10-05), after
     * the Nutrition Care Process (assessment, diagnosis, intervention,
     * monitoring): dated assessments of each candidate (measurements, diet
     * history, findings, diagnosis, plan, next review), a dietary profile
     * (allergies, restrictions, supplements), and the standards that classify
     * the body mass index, set by administrators. Monitoring only: nothing
     * here changes grades, qualification or rank.
     *
     * Defaults are the WHO Asia-Pacific BMI cut-offs adopted by the
     * Department of Health (underweight < 18.5, overweight from 23.0, obese
     * from 27.5) and a waist-to-height ratio of 0.50 as the waist risk line.
     *
     * The BMI itself is not stored: it is always computed from the stored
     * height and weight (NutritionStandards::bmi).
     *
     * Guarded so it can be re-run (MariaDB cannot roll back DDL).
     */
    public function up(): void
    {
        if (! Schema::hasTable('nutrition_standards')) {
            Schema::create('nutrition_standards', function (Blueprint $table) {
                $table->id();
                $table->decimal('underweight_below', 4, 1);
                $table->decimal('overweight_from', 4, 1);
                $table->decimal('obese_from', 4, 1);
                $table->decimal('waist_to_height_risk', 3, 2);
                $table->unsignedSmallInteger('review_interval_days');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });

            DB::statement('ALTER TABLE nutrition_standards ADD CONSTRAINT nutrition_standards_bmi_order_check CHECK (underweight_below > 10 AND underweight_below < overweight_from AND overweight_from < obese_from AND obese_from < 60)');
            DB::statement('ALTER TABLE nutrition_standards ADD CONSTRAINT nutrition_standards_waist_check CHECK (waist_to_height_risk > 0.3 AND waist_to_height_risk < 0.9)');
            DB::statement('ALTER TABLE nutrition_standards ADD CONSTRAINT nutrition_standards_review_check CHECK (review_interval_days BETWEEN 7 AND 365)');

            DB::table('nutrition_standards')->insert([
                'underweight_below' => 18.5,
                'overweight_from' => 23.0,
                'obese_from' => 27.5,
                'waist_to_height_risk' => 0.50,
                'review_interval_days' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('candidate_dietary_profiles')) {
            Schema::create('candidate_dietary_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('food_allergies', 500)->nullable();
                $table->string('dietary_restrictions', 500)->nullable();
                $table->string('supplements', 500)->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('nutrition_assessments')) {
            Schema::create('nutrition_assessments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
                $table->date('assessed_on');
                $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
                // Anthropometric measurements.
                $table->decimal('height_cm', 5, 1);
                $table->decimal('weight_kg', 5, 1);
                $table->decimal('waist_cm', 5, 1)->nullable();
                $table->decimal('body_fat_percent', 4, 1)->nullable();
                // Food and nutrition history.
                $table->string('activity_level', 20)->nullable();
                $table->unsignedTinyInteger('meals_per_day')->nullable();
                $table->text('diet_history')->nullable();
                // Clinical and biochemical findings (lab results come from the medical record).
                $table->text('clinical_findings')->nullable();
                $table->text('lab_findings')->nullable();
                // Nutrition diagnosis (problem, cause, signs) and the plan.
                $table->text('diagnosis')->nullable();
                $table->string('goal', 20)->nullable();
                $table->decimal('target_weight_kg', 5, 1)->nullable();
                $table->unsignedSmallInteger('energy_target_kcal')->nullable();
                $table->text('plan')->nullable();
                $table->date('next_review_on')->nullable();
                $table->timestamps();

                $table->index(['candidate_id', 'assessed_on']);
                $table->index('next_review_on');
            });

            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_height_check CHECK (height_cm BETWEEN 100 AND 250)');
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_weight_check CHECK (weight_kg BETWEEN 25 AND 300)');
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_waist_check CHECK (waist_cm IS NULL OR waist_cm BETWEEN 40 AND 200)');
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_body_fat_check CHECK (body_fat_percent IS NULL OR body_fat_percent BETWEEN 2 AND 70)');
            DB::statement("ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_activity_check CHECK (activity_level IS NULL OR activity_level IN ('light', 'moderate', 'heavy'))");
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_meals_check CHECK (meals_per_day IS NULL OR meals_per_day BETWEEN 1 AND 10)');
            DB::statement("ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_goal_check CHECK (goal IS NULL OR goal IN ('maintain', 'gain', 'lose'))");
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_target_weight_check CHECK (target_weight_kg IS NULL OR target_weight_kg BETWEEN 25 AND 300)');
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_energy_check CHECK (energy_target_kcal IS NULL OR energy_target_kcal BETWEEN 1000 AND 7000)');
            DB::statement('ALTER TABLE nutrition_assessments ADD CONSTRAINT nutrition_assessments_review_check CHECK (next_review_on IS NULL OR next_review_on >= assessed_on)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_assessments');
        Schema::dropIfExists('candidate_dietary_profiles');
        Schema::dropIfExists('nutrition_standards');
    }
};
