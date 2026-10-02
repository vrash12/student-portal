<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CandidateQrCode;
use Illuminate\Support\Facades\DB;

/**
 * Records attendance from a scanned QR code (owner request, 2026-10-02).
 *
 * A code of a candidate of the session's class (not withdrawn) marks them
 * present or late, with the time of the scan. A candidate already present or
 * late keeps that record (a code shown twice changes nothing); one recorded
 * absent or excused is changed to the scanned status, since they are here.
 * Codes of other classes and unknown or replaced codes record nothing, and
 * say nothing about whose code it is. Every change is audited like the roll
 * call (AttendanceService), noting the QR code.
 */
final class QrCheckInService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{result: 'recorded'|'updated'|'already'|'not_in_class'|'unknown', message: string, candidate: ?Candidate, record: ?AttendanceRecord}
     */
    public function checkIn(AttendanceSession $session, string $scanned, AttendanceStatus $status, User $actor): array
    {
        $candidate = CandidateQrCode::find($scanned);
        if ($candidate === null) {
            return ['result' => 'unknown', 'message' => 'Unknown or replaced QR code. The candidate can show the current code on their tablet (My Information).', 'candidate' => null, 'record' => null];
        }
        if (! $candidate->isGradableIn($session->class_batch_id)) {
            $class = $session->classBatch()->value('name');

            return ['result' => 'not_in_class', 'message' => "This code belongs to a candidate who is not in {$class}. Nothing was recorded.", 'candidate' => null, 'record' => null];
        }

        return DB::transaction(function () use ($session, $candidate, $status, $actor): array {
            // Same lock as the roll call, so a scan and a roll-call save never interleave.
            $locked = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $record = AttendanceRecord::query()
                ->where('attendance_session_id', $locked->id)
                ->where('candidate_id', $candidate->id)
                ->first();

            if ($record !== null && $record->status->attended()) {
                return [
                    'result' => 'already',
                    'message' => 'Already recorded as '.$record->status->label().($record->scanned_at === null ? '' : ' at '.$this->time($record)).'.',
                    'candidate' => $candidate,
                    'record' => $record,
                ];
            }

            $before = $record === null ? null : ['status' => $record->status->label(), 'remarks' => $record->remarks];
            $record ??= new AttendanceRecord;
            $record->forceFill([
                'attendance_session_id' => $locked->id,
                'candidate_id' => $candidate->id,
                'status' => $status,
                'recorded_by' => $actor->id,
                'scanned_at' => now(),
            ])->save();

            $number = $candidate->candidate_number;
            $this->audit->record(
                AuditAction::AttendanceRecorded,
                $locked,
                oldValues: [$number => $before],
                newValues: [$number => ['status' => $status->label(), 'remarks' => $record->remarks, 'via' => 'QR code']],
                actor: $actor,
            );

            return [
                'result' => $before === null ? 'recorded' : 'updated',
                'message' => ($before === null ? 'Recorded as ' : 'Changed from '.$before['status'].' to ').$status->label().' at '.$this->time($record).'.',
                'candidate' => $candidate,
                'record' => $record,
            ];
        });
    }

    private function time(AttendanceRecord $record): string
    {
        return $record->scanned_at?->timezone((string) config('institution.timezone'))->format('g:i A') ?? '';
    }
}
