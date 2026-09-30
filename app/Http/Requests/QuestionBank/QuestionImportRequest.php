<?php

namespace App\Http\Requests\QuestionBank;

use App\Models\Subject;
use App\Services\QuestionBank\QuestionImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The question import form: a subject the user teaches and a CSV file.
 * Authorization is on the routes (QuestionPolicy::create); the subject is
 * checked here against User::taughtSubjectIds() and never trusted from the
 * client. The contents of the file are checked by QuestionImportService.
 */
class QuestionImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', Rule::in($this->user()->taughtSubjectIds())],
            'file' => ['required', 'file', 'extensions:csv', 'max:'.QuestionImportService::MAX_FILE_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_id.required' => 'Select the subject the questions belong to.',
            'subject_id.integer' => 'Select one of the subjects you teach.',
            'subject_id.in' => 'Select one of the subjects you teach.',
            'file.required' => 'Choose the CSV file to import.',
            'file.file' => 'The file could not be uploaded. Choose it again and retry.',
            'file.uploaded' => 'The file could not be uploaded. Check that it is at most 1 MB and try again.',
            'file.extensions' => 'Choose a CSV file (ending in .csv). In your spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".',
            'file.max' => 'The file is larger than 1 MB. Split the questions into smaller files and import them one at a time.',
        ];
    }

    public function subject(): Subject
    {
        return Subject::query()->findOrFail((int) $this->validated('subject_id'));
    }

    public function fileContents(): string
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return (string) $file->get();
    }
}
