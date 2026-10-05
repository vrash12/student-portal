<?php

namespace App\Http\Requests\Nutrition;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/** Food allergies, dietary restrictions and supplements; empty clears a field. */
class DietaryProfileRequest extends FormRequest
{
    private const FIELDS = ['food_allergies', 'dietary_restrictions', 'supplements'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];
        foreach (self::FIELDS as $field) {
            $value = $this->input($field);
            $merged[$field] = is_string($value) && Str::squish($value) !== '' ? Str::squish($value) : null;
        }

        $this->merge($merged);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'food_allergies' => ['nullable', 'string', 'max:500'],
            'dietary_restrictions' => ['nullable', 'string', 'max:500'],
            'supplements' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{food_allergies: ?string, dietary_restrictions: ?string, supplements: ?string}
     */
    public function profileData(): array
    {
        return [
            'food_allergies' => $this->input('food_allergies'),
            'dietary_restrictions' => $this->input('dietary_restrictions'),
            'supplements' => $this->input('supplements'),
        ];
    }
}
