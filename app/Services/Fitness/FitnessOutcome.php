<?php

namespace App\Services\Fitness;

use App\Enums\FitnessStatus;

/**
 * A candidate's overall result in a fitness test, from the results of its
 * events: any failed event fails the test; with no failure, the test passes
 * once every event has a result. The overall score is the mean of the event
 * points and exists only when every event has a result.
 */
final readonly class FitnessOutcome
{
    public function __construct(
        public FitnessStatus $status,
        public ?float $points,
    ) {}

    /**
     * @param  list<array{points: float, passed: bool}|null>  $events  one entry per test event; null when not recorded
     */
    public static function of(array $events): self
    {
        $recorded = array_values(array_filter($events, fn (?array $event): bool => $event !== null));

        if ($recorded === []) {
            return new self(FitnessStatus::NotTested, null);
        }

        $complete = count($recorded) === count($events);
        $points = $complete ? round(array_sum(array_column($recorded, 'points')) / count($recorded), 2) : null;

        $status = match (true) {
            in_array(false, array_column($recorded, 'passed'), true) => FitnessStatus::Failed,
            ! $complete => FitnessStatus::Incomplete,
            default => FitnessStatus::Passed,
        };

        return new self($status, $points);
    }
}
