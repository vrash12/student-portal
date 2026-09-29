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
     * @return array{candidate_number: string, first_name: string, last_name: string, class_batch_id: ?int, password: string}
     */
    public function candidateData(): array
    {
        return [
            'candidate_number' => $this->string('candidate_number')->value(),
            'first_name' => $this->string('first_name')->value(),
            'last_name' => $this->string('last_name')->value(),
            'class_batch_id' => $this->classBatchId(),
            'password' => $this->string('password')->value(),
        ];
    }
}
