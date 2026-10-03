<?php

namespace App\Http\Requests\Candidates;

use App\Models\Candidate;

class StoreCandidateRequest extends CandidateRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Candidate::class);
    }

    protected function editedCandidate(): ?Candidate
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function candidateData(): array
    {
        return [
            ...$this->profileData(),
            'candidate_number' => $this->string('candidate_number')->value(),
            'first_name' => $this->string('first_name')->value(),
            'last_name' => $this->string('last_name')->value(),
            'class_batch_id' => $this->classBatchId(),
            'campus_id' => $this->campusId(),
            'password' => $this->string('password')->value(),
        ];
    }
}
