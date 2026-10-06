<?php

namespace App\Services\Schedule;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A calendar week, Monday to Sunday, in the institution's timezone: the
 * unit of the schedule pages. Any date in the week (from the query string)
 * gives the same week; an invalid or missing date gives this week.
 */
final readonly class ScheduleWeek
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function containing(mixed $date): self
    {
        $day = self::validDate($date) !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $date, self::timezone())
            : self::today();
        $start = $day->startOfWeek(CarbonInterface::MONDAY);

        return new self($start, $start->addDays(6));
    }

    /** Today in the institution's timezone (midnight). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** The date when it is a real Y-m-d date between 2000 and 2100, otherwise null. */
    public static function validDate(mixed $date): ?string
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) && $year >= 2000 && $year <= 2100 ? $date : null;
    }

    /**
     * @return list<array{date: string, items: list<array<string, mixed>>}>
     */
    public function emptyDays(): array
    {
        $days = [];
        for ($date = $this->start; $date->lte($this->end); $date = $date->addDay()) {
            $days[] = ['date' => $date->toDateString(), 'items' => []];
        }

        return $days;
    }

    /**
     * @return array{start: string, end: string, previous: string, next: string, today: string, isCurrent: bool}
     */
    public function toArray(): array
    {
        $today = self::today();

        return [
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'previous' => $this->start->subWeek()->toDateString(),
            'next' => $this->start->addWeek()->toDateString(),
            'today' => $today->toDateString(),
            'isCurrent' => $today->betweenIncluded($this->start, $this->end),
        ];
    }

    private static function timezone(): string
    {
        return (string) config('institution.timezone');
    }
}
