import type { AttendanceSummary, CandidateAttendanceSession } from '@/types/attendance';
import type { ConductEntryRow, ConductKindStatus, ConductTotals } from '@/types/conduct';
import type { CandidateQualificationData, PerformanceAreaSummary, QualificationCounts, QualificationStatus } from '@/types/performance';

/**
 * Performance panels of the candidate profile and the candidate portal
 * (CandidatePerformanceRecord on the server). Every figure is calculated by
 * the server; the browser only displays it.
 */

/** The active areas and one candidate's results; `result` is null without a class. */
export interface CandidateQualificationRecord {
    areas: PerformanceAreaSummary[];
    result: CandidateQualificationData | null;
}

/** Staff profile: qualification (rank only when `showRank`, i.e. performance.view). */
export interface ProfileQualification extends CandidateQualificationRecord {
    showRank: boolean;
    /** The viewer may configure the areas (performance.configure). */
    canConfigure: boolean;
}

/** Staff profile: totals and the latest entries, voided entries included and marked. */
export interface ProfileConduct {
    totals: ConductTotals;
    entries: ConductEntryRow[];
    /** The viewer may record and void merits/demerits for this candidate (conduct scope). */
    canManage: boolean;
}

/** The attendance summary of the current class and the latest sessions. */
export interface CandidateAttendanceRecord {
    summary: AttendanceSummary;
    sessions: CandidateAttendanceSession[];
}

/** Staff profile attendance. */
export interface ProfileAttendance extends CandidateAttendanceRecord {
    /** The viewer keeps the attendance of the candidate's class and may open the roll calls. */
    canManage: boolean;
}

/** A merit or demerit as the candidate sees it: only entries that count, no staff names. */
export interface OwnConductEntry {
    id: number;
    /** Calendar date (Y-m-d). */
    occurredOn: string;
    kind: ConductKindStatus;
    type: string;
    points: number;
    reason: string;
}

export interface OwnConduct {
    totals: ConductTotals;
    entries: OwnConductEntry[];
}

/** The qualification status on the portal home card; never a rank. */
export interface PortalPerformanceCard {
    /** False while no performance area is active: the status is then Pending. */
    configured: boolean;
    status: QualificationStatus;
    reasons: string[];
    pending: string[];
}

/** Qualification across the classes of the active period (administrator dashboard). */
export interface QualificationOverviewData {
    period: { id: number; name: string };
    /** False while no performance area is active: nothing is evaluated then. */
    configured: boolean;
    classCount: number;
    counts: QualificationCounts;
    /** The must-pass area failed by the most candidates; null when none is failed. */
    mostCommonUnmet: { areaId: number; name: string; count: number } | null;
}
