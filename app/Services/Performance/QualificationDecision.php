<?php

namespace App\Services\Performance;

use App\Enums\QualificationStatus;

/**
 * Whether a candidate is qualified, and why not (QualificationEngine::qualify).
 */
final readonly class QualificationDecision
{
    /**
     * @param  list<string>  $reasons  "{Area} requirement not met" for each failed must-pass area, in area order
     * @param  list<string>  $pending  names of the must-pass areas still incomplete or without results, in area order
     */
    public function __construct(
        public QualificationStatus $status,
        public array $reasons,
        public array $pending,
    ) {}

    /**
     * @return array{status: array{value: string, label: string, tone: string}, reasons: list<string>, pending: list<string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->toArray(),
            'reasons' => $this->reasons,
            'pending' => $this->pending,
        ];
    }
}
