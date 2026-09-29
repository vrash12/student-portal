<?php

namespace App\Http\Requests\Candidates;

use App\Models\Candidate;
use App\Models\User;
use App\Services\CandidateService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Shared normalization and rules for creating and updating candidates.
 */
abstract class CandidateRequest extends FormRequest
{
    /**
     * The candidate being edited, or null when creating.
     */
    abstract protected function editedCandidate(): ?Candidate;

    protected function prepareForValidation(): void
    {
        $classBatchId = $this->input('class_batch_id');

        $this->merge([
            'candidate_number' => trim((string) $this->input('candidate_number')),
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
            'class_batch_id' => $classBatchId === '' ? null : $classBatchId,
        ]);
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
            'class_batch_id' => ['nullable', 'integer', Rule::exists('class_batches', 'id')],
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
        return ['class_batch_id' => 'class'];
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
}
