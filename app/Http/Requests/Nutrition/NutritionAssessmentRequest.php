<?php

namespace App\Http\Requests\Nutrition;

use App\Enums\ActivityLevel;
use App\Enums\NutritionGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A dietitian's nutrition assessment. Ranges match the CHECK constraints;
 * only height, weight and the date are required. Authorization is on the
 * route (CandidatePolicy::manageNutrition).
 */
class NutritionAssessmentRequest extends FormRequest
{
    private const TEXT_FIELDS = ['diet_history', 'clinical_findings', 'lab_findings', 'diagnosis', 'plan'];

    private const OPTIONAL_FIELDS = [
        'waist_cm', 'body_fat_percent', 'activity_level', 'meals_per_day', 'goal',
        'target_weight_kg', 'energy_target_kcal', 'next_review_on',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->input($field);
            $merged[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        foreach (self::OPTIONAL_FIELDS as $field) {
            $value = $this->input($field);
            $merged[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assessed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'height_cm' => ['required', 'numeric', 'between:100,250', 'decimal:0,1'],
            'weight_kg' => ['required', 'numeric', 'between:25,300', 'decimal:0,1'],
            'waist_cm' => ['nullable', 'numeric', 'between:40,200', 'decimal:0,1'],
            'body_fat_percent' => ['nullable', 'numeric', 'between:2,70', 'decimal:0,1'],
            'activity_level' => ['nullable', Rule::enum(ActivityLevel::class)],
            'meals_per_day' => ['nullable', 'integer', 'between:1,10'],
            'diet_history' => ['nullable', 'string', 'max:5000'],
            'clinical_findings' => ['nullable', 'string', 'max:5000'],
            'lab_findings' => ['nullable', 'string', 'max:5000'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'goal' => ['nullable', Rule::enum(NutritionGoal::class)],
            'target_weight_kg' => ['nullable', 'numeric', 'between:25,300', 'decimal:0,1'],
            'energy_target_kcal' => ['nullable', 'integer', 'between:1000,7000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'next_review_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:assessed_on'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assessed_on.before_or_equal' => 'The assessment date cannot be in the future.',
            'height_cm.between' => 'Enter the height in centimetres (100 to 250).',
            'weight_kg.between' => 'Enter the weight in kilograms (25 to 300).',
            'waist_cm.between' => 'Enter the waist in centimetres (40 to 200).',
            'energy_target_kcal.between' => 'Enter a daily energy target from 1,000 to 7,000 kcal.',
            'next_review_on.after_or_equal' => 'The next review must be on or after the assessment date.',
            '*.decimal' => 'Use at most one decimal place.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assessmentData(): array
    {
        return $this->safe()->only([
            'assessed_on', 'height_cm', 'weight_kg', 'waist_cm', 'body_fat_percent', 'activity_level', 'meals_per_day',
            'diet_history', 'clinical_findings', 'lab_findings', 'diagnosis', 'goal', 'target_weight_kg',
            'energy_target_kcal', 'plan', 'next_review_on',
        ]);
    }
}
