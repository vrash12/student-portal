<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates training sessions and records attendance. Every change is in the
 * audit log with the previous and new values (AGENTS.md §35-§38).
 */
final class AttendanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{held_on: string, title: string, hours: string, notes: ?string}  $details
     */
    public function create(ClassBatch $classBatch, array $details, User $actor): AttendanceSession
    {
        return DB::transaction(function () use ($classBatch, $details, $actor): AttendanceSession {
            $session = new AttendanceSession($this->normalized($details));
            $session->class_batch_id = $classBatch->id;
            $session->created_by = $actor->id;
            $session->save();

            $this->audit->record(AuditAction::AttendanceSessionCreated, $session, newValues: [
                'class' => $classBatch->name,
                ...$this->snapshot($session),
            ], actor: $actor);

            return $session;
        });
    }

    /**
     * Changes the details of a session; its class never changes.
     *
     * @param  array{held_on: string, title: string, hours: string, notes: ?string}  $details
     */
    public function update(AttendanceSession $session, array $details): AttendanceSession
    {
        return DB::transaction(function () use ($session, $details): AttendanceSession {
            $locked = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->fill($this->normalized($details))->save();

            $this->audit->recordChanges(AuditAction::AttendanceSessionUpdated, $locked, $before, $this->snapshot($locked));

            return $locked;
        });
    }

    /** Only a session without recorded attendance can be deleted; records are history. */
    public function delete(AttendanceSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->records()->exists()) {
                throw ValidationException::withMessages(['session' => 'This session has recorded attendance and cannot be deleted.']);
            }

            $this->audit->record(AuditAction::AttendanceSessionDeleted, $locked, oldValues: [
                'class' => $locked->classBatch()->value('name'),
                ...$this->snapshot($locked),
            ]);
            $locked->delete();
        });
    }

    /**
     * Saves the statuses (and optional remarks) of some candidates. Only
     * candidates of the session's class who are not withdrawn can be
     * recorded; unchanged rows are skipped. One audit entry lists every
     * change by candidate number.
     *
     * @param  array<int, array{status: AttendanceStatus, remarks: ?string}>  $entries  candidate id => entry
     * @return int the number of candidates whose attendance changed
     */
    public function record(AttendanceSession $session, array $entries, User $actor): int
    {
        return DB::transaction(function () use ($session, $entries, $actor): int {
            // One save per session at a time, so concurrent saves cannot interleave.
            $locked = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            $candidateIds = array_map('intval', array_keys($entries));
            $candidates = Candidate::query()->whereKey($candidateIds)->get()->keyBy('id');
            $existing = AttendanceRecord::query()
                ->where('attendance_session_id', $locked->id)
                ->whereIn('candidate_id', $candidateIds)
                ->get()
                ->keyBy('candidate_id');

            $before = [];
            $after = [];
            foreach ($entries as $candidateId => $entry) {
                $candidate = $candidates->get($candidateId);
                if ($candidate === null || ! $candidate->isGradableIn($locked->class_batch_id)) {
                    throw ValidationException::withMessages(['entries' => 'Attendance can only be recorded for candidates of this class who are not withdrawn.']);
                }

                /** @var AttendanceRecord|null $record */
                $record = $existing->get($candidate->id);
                if ($record !== null && $record->status === $entry['status'] && $record->remarks === $entry['remarks']) {
                    continue;
                }

                $label = $candidate->candidate_number;
                $before[$label] = $record === null ? null : $this->recordSnapshot($record->status, $record->remarks);
                $after[$label] = $this->recordSnapshot($entry['status'], $entry['remarks']);

                $record ??= new AttendanceRecord;
                $record->forceFill([
                    'attendance_session_id' => $locked->id,
                    'candidate_id' => $candidate->id,
                    'status' => $entry['status'],
                    'remarks' => $entry['remarks'],
                    'recorded_by' => $actor->id,
                ])->save();
            }

            if ($after !== []) {
                $this->audit->record(AuditAction::AttendanceRecorded, $locked, oldValues: $before, newValues: $after, actor: $actor);
            }

            return count($after);
        });
    }

    /**
     * @param  array{held_on: string, title: string, hours: string, notes: ?string}  $details
     * @return array{held_on: string, title: string, hours: ?string, notes: ?string}
     */
    private function normalized(array $details): array
    {
        return [
            'held_on' => $details['held_on'],
            'title' => $details['title'],
            'hours' => DecimalValue::normalize($details['hours']),
            'notes' => $details['notes'],
        ];
    }

    /**
     * @return array{held_on: string, title: string, hours: string, notes: ?string}
     */
    private function snapshot(AttendanceSession $session): array
    {
        return [
            'held_on' => $session->held_on->toDateString(),
            'title' => $session->title,
            'hours' => (string) DecimalValue::normalize((string) $session->hours),
            'notes' => $session->notes,
        ];
    }

    /**
     * @return array{status: string, remarks: ?string}
     */
    private function recordSnapshot(AttendanceStatus $status, ?string $remarks): array
    {
        return ['status' => $status->label(), 'remarks' => $remarks];
    }
}
