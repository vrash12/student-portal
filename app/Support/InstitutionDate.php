<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class InstitutionDate
{
    /** Date filter boundaries use the institution's day, while storage uses UTC. */
    public static function boundary(string $date, bool $afterDay = false): Carbon
    {
        $local = Carbon::parse($date, config('institution.timezone'))->startOfDay();

        return ($afterDay ? $local->addDay() : $local)->utc();
    }
}
