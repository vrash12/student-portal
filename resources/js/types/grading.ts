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

/** A candidate's subject grade, calculated by the server. */
export interface SubjectGrade {
    grade: number | null;
    assessedWeight: number;
    missingScores: number;
    pendingCategories: number;
    status: StatusValue;
    categories: CategoryGrade[];
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
