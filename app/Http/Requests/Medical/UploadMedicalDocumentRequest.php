<?php

namespace App\Http\Requests\Medical;

use App\Enums\MedicalDocumentCategory;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Services\Medical\MedicalDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A candidate's medical document upload. The candidate comes from the
 * signed-in account, never from the request. File type and size are checked
 * again from the contents by MedicalDocumentService.
 */
class UploadMedicalDocumentRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return $this->user()?->candidate !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->trimmedInput('title'),
            'notes' => $this->optionalInput('notes'),
            'document_date' => $this->optionalInput('document_date'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(MedicalDocumentCategory::class)],
            'title' => ['required', 'string', 'max:150'],
            'document_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'max:'.MedicalDocumentService::MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Choose what kind of document this is.',
            'title.required' => 'Give the document a short title, for example "Annual physical examination findings".',
            'document_date.date_format' => 'Enter the date shown on the document.',
            'document_date.before_or_equal' => 'The date of the document cannot be in the future.',
            'file.required' => 'Choose the file to upload.',
            'file.max' => 'This file is too large. The limit is '.intdiv(MedicalDocumentService::MAX_KB, 1024).' MB.',
        ];
    }

    /**
     * @return array{category: string, title: string, document_date: ?string, notes: ?string}
     */
    public function details(): array
    {
        return [
            'category' => (string) $this->validated('category'),
            'title' => (string) $this->validated('title'),
            'document_date' => $this->validated('document_date'),
            'notes' => $this->validated('notes'),
        ];
    }
}
