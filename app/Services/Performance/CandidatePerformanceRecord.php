<?php

namespace App\Services\Performance;

use App\Models\Candidate;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Conduct\ConductLedger;
use Illuminate\Support\Arr;

/**
 * One candidate's performance areas, qualification, merits/demerits and
 * attendance, as the staff candidate profile and the candidate portal show
 * them. It only assembles what QualificationEngine, ConductLedger and
 * AttendanceLedger calculate (no rule is applied here) and leaves out what
 * a reader may not receive. Which reader sees which part is decided by the
 * callers from permissions.
 */
final class CandidatePerformanceRecord
{
    /** Fields of a merit/demerit the candidate sees: no staff names, nothing about voiding. */
    private const OWN_CONDUCT_FIELDS = ['id', 'occurredOn', 'kind', 'type', 'points', 'reason'];

    public function __construct(
        private readonly QualificationEngine $engine,
        private readonly ConductLedger $conduct,
        private readonly AttendanceLedger $attendance,
    ) {}

    /**
     * The active areas and the candidate's results in their current class
     * (null without a class). The class rank is included only when
     * `$withRank` is true: staff with performance.view, never the portal.
     *
     * @return array{areas: list<array<string, mixed>>, result: array<string, mixed>|null}
     */
    public function qualification(Candidate $candidate, bool $withRank): array
    {
        $qualification = $this->engine->forCandidate($candidate, $withRank);

        return [
            'areas' => $qualification === null ? [] : array_map(fn (AreaDefinition $area): array => $area->toArray(), $qualification->areaDefinitions()),
            'result' => $qualification?->toArray($withRank),
        ];
    }

    /**
     * Totals and the latest entries, voided entries included and marked
     * (staff view).
     *
     * @return array{totals: array{merits: int, demerits: int, net: int}, entries: list<array<string, mixed>>}
     */
    public function conduct(Candidate $candidate, ?int $limit = null): array
    {
        return [
            'totals' => $this->conduct->totalsFor([$candidate->id])[$candidate->id],
            'entries' => $this->conduct->history($candidate, $limit),
        ];
    }

    /**
     * The candidate's own view: entries that count only (voided entries are
     * left out, as they are of the totals), without staff names.
     *
     * @return array{totals: array{merits: int, demerits: int, net: int}, entries: list<array<string, mixed>>}
     */
    public function ownConduct(Candidate $candidate): array
    {
        return [
            'totals' => $this->conduct->totalsFor([$candidate->id])[$candidate->id],
            'entries' => array_map(
                fn (array $entry): array => Arr::only($entry, self::OWN_CONDUCT_FIELDS),
                $this->conduct->history($candidate, null, includeVoided: false),
            ),
        ];
    }

    /**
     * The attendance summary over the sessions of the candidate's current
     * class (zeros without a class) and the latest sessions. Remarks are
     * written by staff for staff; `$withRemarks` false leaves them out
     * (candidate portal).
     *
     * @return array{summary: array{sessions: int, present: int, late: int, excused: int, absent: int, unrecorded: int, hours: float, rate: ?float}, sessions: list<array<string, mixed>>}
     */
    public function attendance(Candidate $candidate, int $limit, bool $withRemarks = true): array
    {
        $summary = $candidate->class_batch_id === null
            ? ['sessions' => 0, 'present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unrecorded' => 0, 'hours' => 0.0, 'rate' => null]
            : $this->attendance->summariesFor((int) $candidate->class_batch_id, [$candidate->id])[$candidate->id];

        $sessions = $this->attendance->candidateHistory($candidate, $limit);
        if (! $withRemarks) {
            $sessions = array_map(fn (array $session): array => [...$session, 'remarks' => null], $sessions);
        }

        return ['summary' => $summary, 'sessions' => $sessions];
    }
}
