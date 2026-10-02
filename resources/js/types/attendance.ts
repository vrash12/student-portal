import type { StatusTone } from '@/components/ui/status-badge';
import type { StatusValue } from '@/types/grading';

/**
 * Attendance (AttendanceLedger and AttendanceSessionController on the
 * server). Rates, hours and counts are calculated by the server; the
 * browser only displays them.
 */

export type AttendanceStatusValue = 'present' | 'late' | 'excused' | 'absent';

/** A status with its label and badge tone (App\Enums\AttendanceStatus::toArray). */
export interface AttendanceStatusOption {
    value: AttendanceStatusValue;
    label: string;
    tone: StatusTone;
}

export interface AttendanceCounts {
    present: number;
    late: number;
    excused: number;
    absent: number;
}

/** A row of the session list. */
export interface AttendanceSessionListItem {
    id: number;
    title: string;
    /** Y-m-d. */
    heldOn: string;
    hours: number;
    classBatch: { id: number; name: string };
    /** Records by status, and how many current candidates of the class have one. */
    counts: AttendanceCounts & { recorded: number };
    /** Candidates of the class who are not withdrawn. */
    rosterCount: number;
}

export interface AttendanceSessionDetails {
    id: number;
    title: string;
    /** Y-m-d. */
    heldOn: string;
    hours: number;
    notes: string | null;
    classBatch: { id: number; name: string; period: string };
}

export interface AttendanceRecordDetails {
    status: AttendanceStatusOption;
    remarks: string | null;
    recordedBy: string | null;
    /** ISO timestamp of the last change. */
    recordedAt: string | null;
    /** ISO timestamp of the QR code scan that recorded the candidate; null for the roll call. */
    scannedAt: string | null;
}

/** One candidate on a session's roll call. */
export interface RollCallRow {
    candidate: { id: number; candidateNumber: string; name: string; status: StatusValue };
    /** False for candidates who left the class or withdrew: read-only. */
    recordable: boolean;
    /** The candidate currently belongs to the session's class. */
    inClass: boolean;
    record: AttendanceRecordDetails | null;
}

/** Saved counts of a session; unrecorded counts only candidates who can still be recorded. */
export interface RollCallCounts extends AttendanceCounts {
    unrecorded: number;
}

/**
 * A candidate's attendance over the sessions of their class
 * (AttendanceLedger::summariesFor). The rate leaves out excused and
 * unrecorded sessions and is null until something counts.
 */
export interface AttendanceSummary extends AttendanceCounts {
    sessions: number;
    unrecorded: number;
    /** Hours of the sessions attended (present or late). */
    hours: number;
    rate: number | null;
}

/** One session on a candidate's attendance history (AttendanceLedger::candidateHistory). */
export interface CandidateAttendanceSession {
    id: number;
    title: string;
    heldOn: string;
    hours: number;
    /** Null when the candidate's attendance was not recorded. */
    status: AttendanceStatusOption | null;
    remarks: string | null;
}
