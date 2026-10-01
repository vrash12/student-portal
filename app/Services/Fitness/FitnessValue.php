<?php

namespace App\Services\Fitness;

use App\Enums\FitnessUnit;

/**
 * Reading and writing raw fitness values. Repetitions are whole numbers;
 * times are entered as minutes:seconds ("12:30", "9:05.5") or as seconds
 * ("750") and stored in seconds.
 */
final class FitnessValue
{
    /**
     * The value in storage units, or null when the input is not a valid
     * value for the unit (including out of range).
     */
    public static function parse(string $input, FitnessUnit $unit): ?float
    {
        $input = trim($input);

        $value = match ($unit) {
            FitnessUnit::Repetitions => preg_match('/^\d{1,4}$/', $input) === 1 ? (float) $input : null,
            FitnessUnit::Time => self::parseTime($input),
        };

        if ($value === null || $value > $unit->maximumValue()) {
            return null;
        }

        return $unit === FitnessUnit::Time && $value <= 0 ? null : $value;
    }

    /** "42" for repetitions, "12:30" or "9:05.5" for times. */
    public static function format(float $value, FitnessUnit $unit): string
    {
        if ($unit === FitnessUnit::Repetitions) {
            return (string) (int) round($value);
        }

        $hundredths = (int) round($value * 100);
        $minutes = intdiv($hundredths, 6000);
        $seconds = ($hundredths % 6000) / 100;
        $secondsText = $hundredths % 100 === 0
            ? str_pad((string) (int) $seconds, 2, '0', STR_PAD_LEFT)
            : str_pad(rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.'), 4, '0', STR_PAD_LEFT);

        return $minutes.':'.$secondsText;
    }

    private static function parseTime(string $input): ?float
    {
        if (preg_match('/^(\d{1,3}):([0-5]\d)(?:\.(\d{1,2}))?$/', $input, $parts) === 1) {
            $fraction = isset($parts[3]) ? (float) ('0.'.$parts[3]) : 0.0;

            return (int) $parts[1] * 60 + (int) $parts[2] + $fraction;
        }

        return preg_match('/^\d{1,5}(?:\.\d{1,2})?$/', $input) === 1 ? (float) $input : null;
    }
}
