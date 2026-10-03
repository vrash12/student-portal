<?php

namespace App\Http\Requests\Candidates;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateService;
use App\Support\CampusScope;
use App\Support\CandidateGroups;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Shared normalization and rules for creating and updating candidates.
 */
abstract class CandidateRequest extends FormRequest
{
    use NormalizesTextInput;

    /** Optional unit assignments (free text, see CandidateGroups). */
    private const GROUP_FIELDS = ['company', 'platoon'];

    /**
     * The candidate being edited, or null when creating.
     */
    abstract protected function editedCandidate(): ?Candidate;

    protected function prepareForValidation(): void
    {
        $classBatchId = $this->input('class_batch_id');
        $campusId = $this->input('campus_id');

        $this->merge([
            'candidate_number' => $this->trimmedInput('candidate_number'),
            'first_name' => $this->trimmedInput('first_name'),
            'last_name' => $this->trimmedInput('last_name'),
            'class_batch_id' => $classBatchId === '' ? null : $classBatchId,
            'campus_id' => $campusId === '' ? null : $campusId,
        ]);

        // A candidate in a class is on the class's campus. Without a class and
        // without a campus chosen, an edited candidate stays on their campus,
        // and the only campus the user may choose is filled in for them.
        if ($this->input('class_batch_id') === null && $this->input('campus_id') === null) {
            $assignable = $this->campusScope()->assignableIds();
            $current = $this->editedCandidate()?->campusId();
            if ($current !== null) {
                $this->merge(['campus_id' => $current]);
            } elseif (count($assignable) === 1) {
                $this->merge(['campus_id' => $assignable[0]]);
            }
        }

        // Company and platoon names group candidates in filters, so "Alpha
        // Company" and "Alpha  Company " are stored as the same value.
        foreach (self::GROUP_FIELDS as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => Str::squish($value)]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $candidate = $this->editedCandidate();

        return [
            'candidate_number' => [
                'bail', 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('candidates', 'candidate_number')->ignore($candidate),
                $this->usernameIsAvailable($candidate?->user_id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'training_group' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:'.CandidateGroups::MAX_LENGTH],
            'platoon' => ['nullable', 'string', 'max:'.CandidateGroups::MAX_LENGTH],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096', 'prohibited_if:remove_photo,1,true'],
            'remove_photo' => ['sometimes', 'boolean'],
            // Only classes of a campus the user may work with (owner decision 2026-10-03).
            'class_batch_id' => ['nullable', 'integer', Rule::exists('class_batches', 'id')->where(fn ($classes) => $this->campusScope()->constrain($classes, 'campus_id'))],
            // Candidates without a class are placed on a campus directly: an
            // active campus in the user's scope, or the one they are already on.
            'campus_id' => ['nullable', 'required_without:class_batch_id', 'integer', Rule::in([...$this->campusScope()->assignableIds(), ...$this->currentCampusIds($candidate)])],
            'password' => [
                $candidate === null ? 'required' : 'nullable',
                'string', 'confirmed', Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'candidate_number.unique' => 'This candidate number is already assigned to another candidate.',
            'candidate_number.regex' => 'Use only letters, numbers, periods, hyphens, and underscores.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['class_batch_id' => 'class', 'campus_id' => 'campus'];
    }

    protected function campusScope(): CampusScope
    {
        return $this->user()->campusScope();
    }

    /**
     * The campus an edited candidate is on stays valid even if that campus
     * was deactivated meanwhile.
     *
     * @return list<int>
     */
    private function currentCampusIds(?Candidate $candidate): array
    {
        $campusId = $candidate?->campusId();

        return $campusId === null ? [] : [$campusId];
    }

    /** The campus of a candidate without a class (ignored when the class decides it). */
    protected function campusId(): ?int
    {
        if ($this->classBatchId() !== null) {
            return null;
        }

        $value = $this->input('campus_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * The candidate number doubles as the sign-in username, so it must not
     * collide with any other account's username.
     */
    private function usernameIsAvailable(?int $ownUserId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ownUserId): void {
            $taken = User::query()
                ->where('username', CandidateService::usernameFor((string) $value))
                ->when($ownUserId !== null, fn ($query) => $query->whereKeyNot($ownUserId))
                ->exists();

            if ($taken) {
                $fail('This candidate number is already used as the username of another account.');
            }
        };
    }

    protected function classBatchId(): ?int
    {
        $value = $this->input('class_batch_id');

        return $value === null ? null : (int) $value;
    }

    /** @return array<string, mixed> */
    protected function profileData(): array
    {
        // Preserve optional fields when older clients omit them.
        $data = [];
        foreach (['middle_name', 'suffix', 'training_group', ...self::GROUP_FIELDS] as $field) {
            if ($this->exists($field)) {
                $data[$field] = $this->optionalInput($field);
            }
        }

        return [...$data, 'profile_photo' => $this->file('profile_photo'), 'remove_photo' => $this->boolean('remove_photo')];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $parts = array_map(fn (string $field): string => $this->string($field)->trim()->value(), ['first_name', 'middle_name', 'last_name', 'suffix']);
            if (mb_strlen(implode(' ', array_filter($parts))) > 255) {
                $validator->errors()->add('last_name', 'The combined name must be 255 characters or fewer.');
            }
        }];
    }
}
