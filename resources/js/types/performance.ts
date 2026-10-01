import type { StatusValue } from '@/types/grading';

/**
 * Performance areas and qualification (QualificationEngine on the server).
 * Area grades, the overall score, qualification and rank are calculated by
 * the server; the browser only displays them.
 */

export type PerformanceSourceValue = 'subjects' | 'fitness' | 'conduct' | 'attendance';

export type AreaStatusValue = 'passed' | 'failed' | 'incomplete' | 'not_yet';

export type QualificationStatusValue = 'qualified' | 'not_qualified' | 'pending';

/** A source as a choice on the area form. */
export interface PerformanceSourceOption {
    value: PerformanceSourceValue;
    label: string;
    description: string;
}

/** Conduct areas: rating = base + merit points × merit value − demerit points × demerit value (0–100). */
export interface ConductRule {
    baseRating: number;
    meritValue: number;
    demeritValue: number;
}

/** An active area as results are calculated against it (sent once per page). */
export interface PerformanceAreaSummary {
    id: number;
    name: string;
    description: string | null;
    source: { value: PerformanceSourceValue; label: string };
    /** Share in the overall score; 0 leaves the area out of it. */
    weight: number;
    passingGrade: number;
    mustPass: boolean;
    conductRule: ConductRule | null;
}

export interface AreaStatus extends StatusValue {
    value: AreaStatusValue;
}

export interface QualificationStatus extends StatusValue {
    value: QualificationStatusValue;
}

/** A candidate's result in one area; `areaId` refers to a PerformanceAreaSummary. */
export interface AreaResultData {
    areaId: number;
    /** 0–100 with two decimals; null when there is no grade. */
    grade: number | null;
    status: AreaStatus;
    /** Short explanation from the server, e.g. which subject has missing scores. */
    note: string | null;
}

export interface QualificationDecision {
    status: QualificationStatus;
    /** "{Area} requirement not met", in area order (Not Qualified only). */
    reasons: string[];
    /** Must-pass areas that are incomplete or have no results yet, in area order. */
    pending: string[];
}

export interface CandidateQualificationData {
    candidate: {
        id: number;
        candidateNumber: string;
        name: string;
        company: string | null;
        platoon: string | null;
        status: StatusValue;
    };
    areas: AreaResultData[];
    overall: {
        /** Weighted mean of the area grades; null when no weighted area has a grade. */
        score: number | null;
        /** Every weighted area has a grade. */
        complete: boolean;
    };
    qualification: QualificationDecision;
    /**
     * Class rank (1 = highest overall score; ties share a rank; null =
     * unranked). Only present in staff payloads (performance.view); the
     * candidate portal never receives it.
     */
    rank?: number | null;
}

/** Candidates per qualification status; the buckets add up to the total. */
export interface QualificationCounts {
    total: number;
    qualified: number;
    notQualified: number;
    pending: number;
}

/** Candidates per status in one area. */
export interface AreaCount {
    areaId: number;
    name: string;
    mustPass: boolean;
    passed: number;
    failed: number;
    incomplete: number;
    notYet: number;
    /** Percentage of the candidates who passed the area; null without candidates. */
    passRate: number | null;
}

/** A subject as a choice on the area form, with the area it currently counts toward. */
export interface AreaSubjectOption {
    id: number;
    code: string;
    name: string;
    isActive: boolean;
    area: { id: number; name: string } | null;
}

/** An area on the configuration pages. Decimal values are the stored strings, e.g. "40.00". */
export interface PerformanceAreaRow {
    id: number;
    name: string;
    description: string | null;
    source: { value: PerformanceSourceValue; label: string };
    weight: string;
    passingGrade: string;
    mustPass: boolean;
    baseRating: string | null;
    meritValue: string | null;
    demeritValue: string | null;
    sortOrder: number;
    isActive: boolean;
}

/** Names of the active fitness, conduct and attendance areas, by source. */
export type ActiveSingleSourceAreas = Partial<Record<PerformanceSourceValue, string>>;
