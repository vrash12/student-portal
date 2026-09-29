import type { OverallStanding, StatusValue, SubjectResult } from '@/types/grading';

/**
 * "all": standings over every subject of a class (administrators);
 * "taught": only over the viewer's own subjects; "none": nothing in scope.
 */
export type MonitoringScopeKind = 'all' | 'taught' | 'none';

/** Candidates per overall standing; the buckets add up to `monitored`. */
export interface StandingCounts {
    monitored: number;
    failing: number;
    atRisk: number;
    incomplete: number;
    passing: number;
    noStanding: number;
}

/**
 * A subject needing attention (Failing, At Risk, Incomplete, or missing
 * scores), as decided by the server's single definition.
 */
export interface SubjectConcern {
    classSubjectId: number;
    subject: string;
    grade: number | null;
    standing: StatusValue | null;
    missingScores: number;
    isProvisional: boolean;
}

/** Who, which class, standing, and the most serious subject: the only fields shown on dashboards. */
export interface MonitoredCandidateSummary {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        /** Enrollment status when it is not Enrolled (e.g. "On Leave"); otherwise null. */
        status: string | null;
    };
    classBatch: { id: number; name: string };
    standing: StatusValue | null;
    /** The lowest subject grade and its subject; null when nothing is graded yet. */
    lowest: { grade: number; subject: string; isProvisional: boolean } | null;
    mostSerious: (SubjectConcern & { canOpenGradebook: boolean }) | null;
}

export interface MonitoredCandidateRow extends MonitoredCandidateSummary {
    overall: OverallStanding;
    /** Subjects needing attention, most serious first. */
    concerns: SubjectConcern[];
    missingScores: number;
    /** Present when one subject is selected: that subject's result. */
    subjectResult: {
        classSubjectId: number;
        result: SubjectResult;
        canOpenGradebook: boolean;
    } | null;
}

/** A class subject with failing or at-risk candidates. */
export interface SubjectAttention {
    classSubjectId: number;
    classBatch: { id: number; name: string };
    subject: { id: number; name: string };
    failing: number;
    atRisk: number;
    incomplete: number;
}

/** Monitoring summary for a dashboard panel (active period). */
export interface MonitoringSummary {
    period: { id: number; name: string };
    scope: MonitoringScopeKind;
    /** The period has passing and warning grades, so standings can exist. */
    hasThresholds: boolean;
    counts: StandingCounts;
    requiringAttention: MonitoredCandidateSummary[];
}
