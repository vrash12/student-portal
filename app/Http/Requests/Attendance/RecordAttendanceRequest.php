<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Attendance of some candidates of a session (only the changed rows):
 * entries[candidate id] = {status, remarks}. Each invalid value is reported
 * on its own field (entries.{candidate}.status / .remarks). The session's
 * class must be in the user's scope; class membership is checked by
 * AttendanceService.
 */
class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('attendanceSession');

        return $session instanceof AttendanceSession && $this->user()->can('manage', $session);
    }

    /** Trims remarks; an empty remark is stored as null. */
    protected function prepareForValidation(): void
    {
        $entries = $this->input('entries');
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $candidateId => $entry) {
            if (is_array($entry) && array_key_exists('remarks', $entry) && is_string($entry['remarks'])) {
                $remarks = trim($entry['remarks']);
                $entries[$candidateId]['remarks'] = $remarks === '' ? null : $remarks;
            }
        }

        $this->merge(['entries' => $entries]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*' => ['required', 'array:status,remarks'],
            'entries.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'entries.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.required' => 'Choose the attendance of at least one candidate.',
            'entries.min' => 'Choose the attendance of at least one candidate.',
            'entries.max' => 'Save at most 500 candidates at a time.',
            'entries.*.array' => 'The attendance could not be read. Reload the page and try again.',
            'entries.*.status.required' => 'Choose Present, Late, Excused or Absent.',
            'entries.*.status.enum' => 'Choose Present, Late, Excused or Absent.',
            'entries.*.remarks.string' => 'Enter the remarks as text.',
            'entries.*.remarks.max' => 'Remarks may be up to 255 characters.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys((array) $this->input('entries')) as $candidateId) {
                if (filter_var($candidateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $validator->errors()->add('entries', 'The attendance could not be read. Reload the page and try again.');

                    return;
                }
            }
        }];
    }

    /**
     * @return array<int, array{status: AttendanceStatus, remarks: ?string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ((array) $this->validated('entries') as $candidateId => $entry) {
            $entries[(int) $candidateId] = [
                'status' => AttendanceStatus::from((string) $entry['status']),
                'remarks' => isset($entry['remarks']) ? (string) $entry['remarks'] : null,
            ];
        }

        return $entries;
    }
}
