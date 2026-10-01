<?php

namespace App\Services\Performance;

use App\Models\Candidate;

/**
 * One candidate's area results, overall score, qualification and class
 * rank, produced only by QualificationEngine. Nothing is stored: results
 * are calculated when read.
 *
 * The class rank is staff-only (performance.view): toArray() leaves it out
 * unless the caller asks for it, so the candidate portal never receives it.
 */
final readonly class CandidateQualification
{
    /**
     * @param  list<AreaResult>  $areas  one per active area, in area order
     */
    public function __construct(
        public Candidate $candidate,
        public array $areas,
        /** Weighted mean of the area grades (two decimals), or null when no weighted area has a grade. */
        public ?float $overall,
        /** Every weighted area has a grade, so the overall score is not partial. */
        public bool $overallComplete,
        public QualificationDecision $qualification,
        /** 1 = highest overall score in the class; ties share a rank; null = unranked. */
        public ?int $rank = null,
    ) {}

    public function withRank(?int $rank): self
    {
        return new self($this->candidate, $this->areas, $this->overall, $this->overallComplete, $this->qualification, $rank);
    }

    public function result(int $areaId): ?AreaResult
    {
        foreach ($this->areas as $result) {
            if ($result->area->id === $areaId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return list<AreaDefinition>
     */
    public function areaDefinitions(): array
    {
        return array_map(fn (AreaResult $result): AreaDefinition => $result->area, $this->areas);
    }

    /**
     * @param  bool  $withRank  staff output only (performance.view); never for the candidate portal
     * @return array<string, mixed>
     */
    public function toArray(bool $withRank = false): array
    {
        $candidate = $this->candidate;
        $payload = [
            'candidate' => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'company' => $candidate->company,
                'platoon' => $candidate->platoon,
                'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
            ],
            'areas' => array_map(fn (AreaResult $result): array => $result->toArray(), $this->areas),
            'overall' => ['score' => $this->overall, 'complete' => $this->overallComplete],
            'qualification' => $this->qualification->toArray(),
        ];

        return $withRank ? $payload + ['rank' => $this->rank] : $payload;
    }
}
