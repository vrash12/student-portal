<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\ScheduleEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add an entry to a class's schedule (class and details) or change its
 * details. The `can:schedule.manage` route middleware applies to both; a
 * change also needs the entry's class in the user's scope. When adding, the
 * chosen class is checked by the controller after validation, and the
 * subject, instructor and dates by ScheduleService.
 */
class ScheduleEntryRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        $entry = $this->route('scheduleEntry');

        return ! $entry instanceof ScheduleEntry || $this->user()->can('manage', $entry);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->trimmedInput('title'),
            'location' => $this->optionalInput('location'),
            'notes' => $this->optionalInput('notes'),
            'class_subject_id' => $this->optionalInput('class_subject_id'),
            'instructor_id' => $this->optionalInput('instructor_id'),
            'ends_on' => $this->optionalInput('ends_on'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('scheduleEntry') instanceof ScheduleEntry;

        return [
            'class_batch_id' => $creating ? ['required', 'integer', Rule::exists('class_batches', 'id')] : ['prohibited'],
            'class_subject_id' => ['nullable', 'integer', Rule::exists('class_subjects', 'id')],
            'instructor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'title' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'repeats_weekly' => ['required', 'boolean'],
            'ends_on' => ['nullable', 'required_if_accepted:repeats_weekly', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_batch_id.required' => 'Choose the class.',
            'title.required' => 'Enter what happens, such as Subject 1 lecture or Physical training.',
            'starts_on.required' => 'Enter the date.',
            'starts_on.date_format' => 'Enter the date.',
            'ends_on.required_if_accepted' => 'Enter the last date of the weekly session.',
            'ends_on.after_or_equal' => 'The last date cannot be before the first.',
            'start_time.required' => 'Enter the start time.',
            'start_time.date_format' => 'Enter the start time, such as 08:00.',
            'end_time.required' => 'Enter the end time.',
            'end_time.date_format' => 'Enter the end time, such as 09:30.',
            'end_time.after' => 'The session must end after it starts.',
        ];
    }

    /**
     * @return array{class_subject_id: ?int, instructor_id: ?int, title: string, location: ?string, starts_on: string, repeats_weekly: bool, ends_on: ?string, start_time: string, end_time: string, notes: ?string}
     */
    public function details(): array
    {
        $repeats = (bool) $this->validated('repeats_weekly');

        return [
            'class_subject_id' => $this->optionalId('class_subject_id'),
            'instructor_id' => $this->optionalId('instructor_id'),
            'title' => (string) $this->validated('title'),
            'location' => $this->validated('location'),
            'starts_on' => (string) $this->validated('starts_on'),
            'repeats_weekly' => $repeats,
            'ends_on' => $repeats ? (string) $this->validated('ends_on') : null,
            'start_time' => (string) $this->validated('start_time'),
            'end_time' => (string) $this->validated('end_time'),
            'notes' => $this->validated('notes'),
        ];
    }

    private function optionalId(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null ? null : (int) $value;
    }
}
