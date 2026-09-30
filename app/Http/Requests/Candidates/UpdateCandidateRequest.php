<?php

namespace App\Http\Requests\Candidates;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use Illuminate\Validation\Rule;

class UpdateCandidateRequest extends CandidateRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->editedCandidate());
    }

    protected function editedCandidate(): Candidate
    {
        /** @var Candidate $candidate */
        $candidate = $this->route('candidate');

        return $candidate;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::enum(CandidateStatus::class)],
            'account_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'account_active' => 'account status'];
    }

    /**
     * @return array<string, mixed>
     */
    public function candidateData(): array
    {
        $password = $this->string('password')->value();

        return [
            'candidate_number' => $this->string('candidate_number')->value(),
            ...$this->profileData(),
            'first_name' => $this->string('first_name')->value(),
            'last_name' => $this->string('last_name')->value(),
            'class_batch_id' => $this->classBatchId(),
            'status' => CandidateStatus::from($this->string('status')->value()),
            'account_active' => $this->boolean('account_active'),
            'password' => $password === '' ? null : $password,
        ];
    }
}
