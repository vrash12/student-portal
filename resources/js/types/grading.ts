import type { StatusTone } from '@/components/ui/status-badge';

/** A server-provided status: always rendered as text with its tone (never color alone). */
export interface StatusValue {
    value: string;
    label: string;
    tone: StatusTone;
}

/** Header and breadcrumb context of every grading page. */
export interface OfferingContext {
    id: number;
    classBatch: { id: number; name: string };
    subject: { code: string; name: string };
    period: { name: string; isActive: boolean };
}

/** One category of a class subject's grading scheme. Weight is a percentage, e.g. "20". */
export interface GradingCategory {
    id: number;
    name: string;
    weight: string;
    assessmentCount: number;
}

export interface CandidateSummary {
    id: number;
    candidateNumber: string;
    name: string;
    status: StatusValue;
}

/**
 * A candidate's result in one category, calculated by the server's
 * GradeCalculationService. Never recompute these values in the browser.
 */
export interface CategoryGrade {
    categoryId: number;
    name: string;
    weight: number;
    assessmentCount: number;
    scoredCount: number;
    missingCount: number;
    earned: number;
    possible: number;
    percentage: number | null;
    weightedScore: number | null;
}

/** A candidate's subject grade and academic standing, calculated by the server. */
export interface SubjectGrade {
    grade: number | null;
    assessedWeight: number;
    missingScores: number;
    pendingCategories: number;
    /** The grade (and standing) is current, not final: some categories have no finalized assessment yet. */
    isProvisional: boolean;
    /** How complete the data behind the grade is (Complete, In Progress, Missing Scores, ...). */
    status: StatusValue;
    /**
     * Passing, At Risk, Failing, or Incomplete. Null when the academic period
     * has no passing and warning grades, or when there is nothing to judge yet.
     */
    standing: StatusValue | null;
    categories: CategoryGrade[];
}

/** A training phase of the course (Phase 1, Phase 2, … or the phases OCS uses). */
export interface TrainingPhaseSummary {
    id: number;
    /** Position in the course: Phase 1 comes before Phase 2. */
    number: number;
    name: string;
    /** Y-m-d; the phase lies inside its academic year. */
    startsOn: string;
    endsOn: string;
}

/** A candidate's average in one training phase, calculated by the server (unit-weighted). */
export interface PhaseAverage {
    /** Null for the subjects not placed in a phase. */
    phase: TrainingPhaseSummary | null;
    /** Null while no subject of the phase has a grade. */
    average: number | null;
    /** Every subject of the phase has a final grade; until then the average is a current one. */
    complete: boolean;
    gradedSubjects: number;
    totalSubjects: number;
    /** Total units of the phase, display form. */
    units: string;
    subjects: Array<{ classSubjectId: number; name: string; units: string }>;
}

/** Cumulative General Point Average: the unit-weighted average of every subject grade so far. */
export interface Cgpa {
    grade: number | null;
    complete: boolean;
    gradedSubjects: number;
    totalSubjects: number;
}

/** A candidate's grades for the course of their class, by training phase. */
export interface CourseRecord {
    phases: PhaseAverage[];
    cgpa: Cgpa;
}

/** A subject grade without the per-category breakdown (monitoring payloads). */
export type SubjectResult = Omit<SubjectGrade, 'categories'>;

/** Passing and warning grades of an academic period (0–100). */
export interface GradingThresholds {
    passingGrade: number;
    warningGrade: number;
}

/** A candidate's standing across a set of subjects, decided by the server. */
export interface OverallStanding {
    /** The most serious subject standing; null when no subject has one. */
    standing: StatusValue | null;
    basedOnSubjects: number;
    totalSubjects: number;
    isProvisional: boolean;
}

export interface AssessmentSummary {
    id: number;
    title: string;
    category: { id: number; name: string };
    maxScore: string;
    assessedOn: string | null;
    status: StatusValue;
    scoredCount: number;
}
