<?php

namespace App\Http\Requests\Announcements;

use App\Enums\AnnouncementAudience;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Post a notice (audience, campus or class, text, dates) or change one
 * (text and dates only: the audience is fixed). Dates are entered in the
 * institution's timezone and stored in UTC. Whether the user may post to the
 * chosen audience is checked by the controller after validation.
 */
class AnnouncementRequest extends FormRequest
{
    use NormalizesTextInput;

    private const DATE_TIME = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        $announcement = $this->route('announcement');

        return ! $announcement instanceof Announcement || $this->user()->can('manage', $announcement);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->trimmedInput('title'),
            'body' => $this->trimmedInput('body'),
            'publishes_at' => $this->optionalInput('publishes_at'),
            'expires_at' => $this->optionalInput('expires_at'),
            'campus_id' => $this->optionalInput('campus_id'),
            'class_batch_id' => $this->optionalInput('class_batch_id'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('announcement') instanceof Announcement;

        return [
            'audience' => $creating ? ['required', Rule::enum(AnnouncementAudience::class)] : ['prohibited'],
            'campus_id' => $creating
                ? ['nullable', 'required_if:audience,'.AnnouncementAudience::Campus->value, 'prohibited_unless:audience,'.AnnouncementAudience::Campus->value, 'integer', Rule::exists('campuses', 'id')]
                : ['prohibited'],
            'class_batch_id' => $creating
                ? ['nullable', 'required_if:audience,'.AnnouncementAudience::ClassBatch->value, 'prohibited_unless:audience,'.AnnouncementAudience::ClassBatch->value, 'integer', Rule::exists('class_batches', 'id')]
                : ['prohibited'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'is_important' => ['required', 'boolean'],
            // Optional when posting (blank: show now); kept when changing.
            'publishes_at' => [$creating ? 'nullable' : 'required', 'date_format:'.self::DATE_TIME],
            'expires_at' => ['nullable', 'date_format:'.self::DATE_TIME],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audience.required' => 'Choose who sees the notice.',
            'campus_id.required_if' => 'Choose the campus.',
            'class_batch_id.required_if' => 'Choose the class.',
            'title.required' => 'Enter a title.',
            'title.max' => 'Keep the title to 150 characters.',
            'body.required' => 'Enter the message.',
            'body.max' => 'Keep the message to 5,000 characters.',
            'publishes_at.required' => 'Enter when the notice starts showing.',
            'publishes_at.date_format' => 'Enter a valid date and time.',
            'expires_at.date_format' => 'Enter a valid date and time.',
        ];
    }

    public function audience(): AnnouncementAudience
    {
        return AnnouncementAudience::from((string) $this->validated('audience'));
    }

    public function campusId(): ?int
    {
        $campusId = $this->validated('campus_id');

        return $campusId === null ? null : (int) $campusId;
    }

    public function classBatchId(): ?int
    {
        $classBatchId = $this->validated('class_batch_id');

        return $classBatchId === null ? null : (int) $classBatchId;
    }

    /**
     * @return array{title: string, body: string, is_important: bool, publishes_at: ?CarbonImmutable, expires_at: ?CarbonImmutable}
     */
    public function details(): array
    {
        return [
            'title' => (string) $this->validated('title'),
            'body' => (string) $this->validated('body'),
            'is_important' => (bool) $this->validated('is_important'),
            'publishes_at' => $this->moment('publishes_at'),
            'expires_at' => $this->moment('expires_at'),
        ];
    }

    private function moment(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        // "!": seconds are zero, not the current time's.
        return $value === null ? null : CarbonImmutable::createFromFormat('!'.self::DATE_TIME, (string) $value, (string) config('institution.timezone'))->utc();
    }
}
