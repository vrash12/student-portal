<?php

namespace App\Http\Requests\Nutrition;

use Illuminate\Foundation\Http\FormRequest;

/** The BMI cut-offs, waist-to-height risk line and review interval (ranges match the CHECK constraints). */
class NutritionStandardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'underweight_below' => ['required', 'numeric', 'between:12,25', 'decimal:0,1'],
            'overweight_from' => ['required', 'numeric', 'between:18,35', 'decimal:0,1', 'gt:underweight_below'],
            'obese_from' => ['required', 'numeric', 'between:20,45', 'decimal:0,1', 'gt:overweight_from'],
            'waist_to_height_risk' => ['required', 'numeric', 'between:0.35,0.8', 'decimal:0,2'],
            'review_interval_days' => ['required', 'integer', 'between:7,365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overweight_from.gt' => 'Overweight must start above the underweight line.',
            'obese_from.gt' => 'Obese must start above the overweight line.',
        ];
    }

    /**
     * @return array{underweight_below: float, overweight_from: float, obese_from: float, waist_to_height_risk: float, review_interval_days: int}
     */
    public function standardsData(): array
    {
        return [
            'underweight_below' => (float) $this->input('underweight_below'),
            'overweight_from' => (float) $this->input('overweight_from'),
            'obese_from' => (float) $this->input('obese_from'),
            'waist_to_height_risk' => (float) $this->input('waist_to_height_risk'),
            'review_interval_days' => $this->integer('review_interval_days'),
        ];
    }
}
