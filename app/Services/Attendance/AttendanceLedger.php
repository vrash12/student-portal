<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads attendance. The one authoritative attendance calculation
 * (AGENTS.md §15); performance areas and every page read it from here:
 *
 * - rate = (present + late) ÷ (present + late + absent) × 100, rounded half
 *   up to two decimals; excused and unrecorded sessions are left out; null
 *   when nothing counts yet;
 * - hours = the sum of the hours of the sessions attended (present or late).
 */
final class AttendanceLedger
{
    /**
     * Attendance of candidates over the sessions of a class. Every requested
     * id is present in the result (zeros and a null rate when none).
     *
     * @param  array<int, int>  $candidateIds
     * @return array<int, array{sessions: int, present: int, late: int, excused: int, absent: int, unrecorded: int, hours: float, rate: ?float}>
     */
    public function summariesFor(int $classBatchId, array $candidateIds): array
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if ($candidateIds === []) {
            return [];
        }

        $sessionCount = AttendanceSession::query()->where('class_batch_id', $classBatchId)->count();

        $counts = array_fill_keys($candidateIds, ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'hundredths' => 0]);
        if ($sessionCount > 0) {
            $rows = DB::table('attendance_records')
                ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
                ->where('attendance_sessions.class_batch_id', $classBatchId)
                ->whereIn('attendance_records.candidate_id', $candidateIds)
                ->groupBy('attendance_records.candidate_id', 'attendance_records.status')
                ->select('attendance_records.candidate_id', 'attendance_records.status')
                ->selectRaw('count(*) as total, sum(attendance_sessions.hours) as hours')
                ->get();

            foreach ($rows as $row) {
                $status = AttendanceStatus::from((string) $row->status);
                $candidateId = (int) $row->candidate_id;
                $counts[$candidateId][$status->value] = (int) $row->total;
                if ($status->attended()) {
                    // Whole hundredths, so the sum is exact.
                    $counts[$candidateId]['hundredths'] += DecimalValue::toHundredths((string) $row->hours);
                }
            }
        }

        $summaries = [];
        foreach ($counts as $candidateId => $count) {
            $recorded = $count['present'] + $count['late'] + $count['excused'] + $count['absent'];
            $summaries[$candidateId] = [
                'sessions' => $sessionCount,
                'present' => $count['present'],
                'late' => $count['late'],
                'excused' => $count['excused'],
                'absent' => $count['absent'],
                'unrecorded' => max(0, $sessionCount - $recorded),
                'hours' => (float) ($count['hundredths'] / 100),
                'rate' => self::rate($count['present'], $count['late'], $count['absent']),
            ];
        }

        return $summaries;
    }

    /**
     * The attendance rate: (present + late) ÷ (present + late + absent) × 100,
     * rounded half up to two decimals with exact integer arithmetic. Null
     * when the divisor is 0 (nothing counts yet).
     */
    public static function rate(int $present, int $late, int $absent): ?float
    {
        $attended = $present + $late;
        $counted = $attended + $absent;
        if ($counted <= 0) {
            return null;
        }

        // round(attended × 10000 ÷ counted) in hundredths of a percent, half up.
        return (float) (intdiv(2 * $attended * 10000 + $counted, 2 * $counted) / 100);
    }

    /**
     * The sessions of the candidate's current class, newest first, with the
     * candidate's status at each (null when not recorded).
     *
     * @return list<array{id: int, title: string, heldOn: string, hours: float, status: array{value: string, label: string, tone: string}|null, remarks: ?string}>
     */
    public function candidateHistory(Candidate $candidate, ?int $limit = null): array
    {
        if ($candidate->class_batch_id === null) {
            return [];
        }

        $sessions = AttendanceSession::query()
            ->where('class_batch_id', $candidate->class_batch_id)
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit(max(0, (int) $limit)))
            ->get();

        $records = AttendanceRecord::query()
            ->where('candidate_id', $candidate->id)
            ->whereIn('attendance_session_id', $sessions->modelKeys())
            ->get()
            ->keyBy('attendance_session_id');

        return $sessions->map(function (AttendanceSession $session) use ($records): array {
            /** @var AttendanceRecord|null $record */
            $record = $records->get($session->id);

            return [
                'id' => $session->id,
                'title' => $session->title,
                'heldOn' => $session->held_on->toDateString(),
                'hours' => (float) $session->hours,
                'status' => $record?->status->toArray(),
                'remarks' => $record?->remarks,
            ];
        })->values()->all();
    }

    /**
     * The roll of a session: the class's current candidates who are not
     * withdrawn, plus anyone with a recorded status (read-only once they
     * left the class or withdrew), by candidate number; and the counts.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array{present: int, late: int, excused: int, absent: int, unrecorded: int}}
     */
    public function rollCall(AttendanceSession $session): array
    {
        $records = AttendanceRecord::query()
            ->where('attendance_session_id', $session->id)
            ->with('recorder:id,name')
            ->get()
            ->keyBy('candidate_id');

        $candidates = Candidate::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $roster) => $roster->gradableIn($session->class_batch_id))
                ->orWhereIn('id', $records->keys()->all()))
            ->orderBy('candidate_number')
            ->orderBy('id')
            ->get();

        $counts = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'unrecorded' => 0];
        $rows = [];
        foreach ($candidates as $candidate) {
            /** @var AttendanceRecord|null $record */
            $record = $records->get($candidate->id);
            $recordable = $candidate->isGradableIn($session->class_batch_id);
            if ($record !== null) {
                $counts[$record->status->value]++;
            } elseif ($recordable) {
                $counts['unrecorded']++;
            }

            $rows[] = [
                'candidate' => [
                    'id' => $candidate->id,
                    'candidateNumber' => $candidate->candidate_number,
                    'name' => $candidate->full_name,
                    'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                ],
                // False for candidates who left the class or withdrew: their row is read-only.
                'recordable' => $recordable,
                'inClass' => (int) $candidate->class_batch_id === $session->class_batch_id,
                'record' => $record === null ? null : [
                    'status' => $record->status->toArray(),
                    'remarks' => $record->remarks,
                    'recordedBy' => $record->recorder?->name,
                    'recordedAt' => $record->updated_at?->toIso8601String(),
                ],
            ];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Counts of each session for the session list: records by status, and
     * how many of the class's current candidates (not withdrawn) have one.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, array{present: int, late: int, excused: int, absent: int, recorded: int}>
     */
    public function sessionCounts(array $sessionIds): array
    {
        $counts = array_fill_keys($sessionIds, ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'recorded' => 0]);
        if ($sessionIds === []) {
            return $counts;
        }

        $rows = DB::table('attendance_records')
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->join('candidates', 'candidates.id', '=', 'attendance_records.candidate_id')
            ->whereIn('attendance_records.attendance_session_id', $sessionIds)
            ->groupBy('attendance_records.attendance_session_id')
            ->select('attendance_records.attendance_session_id')
            ->selectRaw("sum(case when attendance_records.status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when attendance_records.status = 'late' then 1 else 0 end) as late")
            ->selectRaw("sum(case when attendance_records.status = 'excused' then 1 else 0 end) as excused")
            ->selectRaw("sum(case when attendance_records.status = 'absent' then 1 else 0 end) as absent")
            ->selectRaw(
                'sum(case when candidates.class_batch_id = attendance_sessions.class_batch_id and candidates.status <> ? then 1 else 0 end) as recorded',
                [CandidateStatus::Withdrawn->value],
            )
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->attendance_session_id] = [
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'excused' => (int) $row->excused,
                'absent' => (int) $row->absent,
                'recorded' => (int) $row->recorded,
            ];
        }

        return $counts;
    }

    /**
     * Candidates of each class who are not withdrawn (the roll to record).
     *
     * @param  list<int>  $classBatchIds
     * @return array<int, int>
     */
    public function rosterSizes(array $classBatchIds): array
    {
        if ($classBatchIds === []) {
            return [];
        }

        return Candidate::query()
            ->whereIn('class_batch_id', $classBatchIds)
            ->where('status', '!=', CandidateStatus::Withdrawn->value)
            ->selectRaw('class_batch_id, count(*) as aggregate')
            ->groupBy('class_batch_id')
            ->pluck('aggregate', 'class_batch_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
