<?php

namespace App\Http\Requests\Examinations;

use Illuminate\Foundation\Http\FormRequest;

class GradeEssayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('grade', $this->route('attempt'));
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|integer|min:1',
            'score' => 'required|numeric|decimal:0,2|min:0',
            'version' => 'required|integer|min:0',
            'comment' => 'nullable|string|max:5000',
            'reason' => 'nullable|string|max:1000',
            'next' => 'sometimes|boolean',
        ];
    }
}
