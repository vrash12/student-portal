<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class SaveAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('attempt'));
    }

    public function rules(): array
    {
        return ['revision' => 'required|integer|min:0', 'position' => 'required|integer|min:0', 'next_position' => 'sometimes|integer|min:0', 'answer' => ['nullable', function ($attribute, $value, $fail) {
            if (! is_int($value) && ! is_string($value)) {
                $fail('Invalid answer.');
            }
            if (is_string($value) && mb_strlen($value) > 20000) {
                $fail('Answers may contain at most 20,000 characters.');
            }
        }], 'flagged' => 'sometimes|boolean'];
    }
}
