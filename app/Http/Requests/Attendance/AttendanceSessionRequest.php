<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a training session (class and details) or update its details.
 * The `can:attendance.manage` route middleware applies to both; an update
 * also requires the session's class to be in the user's scope. When
 * creating, the chosen class is checked by the controller after validation.
 */
class AttendanceSessionRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        $session = $this->route('attendanceSession');

        return ! $session instanceof AttendanceSession || $this->user()->can('manage', $session);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->trimmedInput('title'),
            'hours' => $this->trimmedInput('hours'),
            'notes' => $this->optionalInput('notes'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('attendanceSession') instanceof AttendanceSession;
        // Sessions record training that was held: today at the latest, in the institution's timezone.
        $today = now()->timezone((string) config('institution.timezone'))->toDateString();

        return [
            'class_batch_id' => $creating ? ['required', 'integer', Rule::exists('class_batches', 'id')] : ['prohibited'],
            'held_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today],
            'title' => ['required', 'string', 'max:150'],
            'hours' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:24'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_batch_id.required' => 'Choose the class that attended the session.',
            'held_on.required' => 'Enter the session date.',
            'held_on.date_format' => 'Enter the session date.',
            'held_on.before_or_equal' => 'The session date cannot be in the future. Create the session on or after the day it is held.',
            'title.required' => 'Enter a title for the session.',
            'hours.required' => 'Enter the length of the session in hours.',
            'hours.numeric' => 'Enter the hours as a number, such as 1.5.',
            'hours.decimal' => 'Enter the hours with up to two decimals, such as 1.5.',
            'hours.gt' => 'A session must last more than 0 hours.',
            'hours.max' => 'A session cannot be longer than 24 hours.',
        ];
    }

    /**
     * @return array{held_on: string, title: string, hours: string, notes: ?string}
     */
    public function details(): array
    {
        return [
            'held_on' => (string) $this->validated('held_on'),
            'title' => (string) $this->validated('title'),
            'hours' => (string) $this->validated('hours'),
            'notes' => $this->validated('notes'),
        ];
    }
}
