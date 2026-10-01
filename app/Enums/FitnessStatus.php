<?php

namespace App\Enums;

/**
 * A candidate's result in one fitness test, decided by FitnessScoring:
 * failing any event fails the test; otherwise the test passes once every
 * event has a result.
 */
enum FitnessStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Incomplete = 'incomplete';
    case NotTested = 'not_tested';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Incomplete => 'Incomplete',
            self::NotTested => 'Not Tested',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Incomplete => 'warning',
            self::NotTested => 'neutral',
        };
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
