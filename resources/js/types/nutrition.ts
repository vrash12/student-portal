import type { StatusTone } from '@/components/ui/status-badge';

/** Body mass index category, by the standards in force (never stored). */
export interface NutritionStatusBadge {
    value: 'underweight' | 'normal' | 'overweight' | 'obese';
    label: string;
    tone: StatusTone;
}

export interface Option {
    value: string;
    label: string;
}

/** BMI cut-offs, waist-to-height risk line and default time to the next review (set by administrators). */
export interface NutritionStandards {
    underweightBelow: number;
    overweightFrom: number;
    obeseFrom: number;
    waistToHeightRisk: number;
    reviewIntervalDays: number;
}

/**
 * One assessment. The dietitian's notes (assessedBy, dietHistory,
 * clinicalFindings, labFindings, diagnosis) are null for candidates.
 */
export interface NutritionAssessment {
    id: number;
    assessedOn: string;
    heightCm: number;
    weightKg: number;
    waistCm: number | null;
    bodyFatPercent: number | null;
    bmi: number;
    status: NutritionStatusBadge;
    waistToHeight: number | null;
    waistAtRisk: boolean;
    activityLevel: Option | null;
    mealsPerDay: number | null;
    goal: Option | null;
    targetWeightKg: number | null;
    energyTargetKcal: number | null;
    plan: string | null;
    nextReviewOn: string | null;
    assessedBy: string | null;
    dietHistory: string | null;
    clinicalFindings: string | null;
    labFindings: string | null;
    diagnosis: string | null;
}

export interface DietaryProfile {
    foodAllergies: string | null;
    dietaryRestrictions: string | null;
    supplements: string | null;
    updatedAt: string | null;
    updatedBy: string | null;
}

/** The nutrition panel of the staff candidate profile: "full" for nutrition staff, "summary" for instructors of the class. */
export interface ProfileNutrition {
    scope: 'full' | 'summary';
    assessedOn: string | null;
    status: NutritionStatusBadge | null;
    bmi: number | null;
    weightKg: number | null;
    nextReviewOn: string | null;
    foodAllergies: string | null;
    dietaryRestrictions: string | null;
    recordUrl: string | null;
}

export interface NutritionCandidateSummary {
    id: number;
    number: string;
    name: string;
    className: string | null;
    campus: string | null;
    status: string;
    photoUrl: string | null;
}

/** One candidate in the Nutrition list and on the dashboard. */
export interface NutritionRow {
    id: number;
    number: string;
    name: string;
    classId: number | null;
    className: string | null;
    assessments: number;
    assessedOn: string | null;
    weightKg: number | null;
    bmi: number | null;
    status: NutritionStatusBadge | null;
    waistToHeight: number | null;
    waistAtRisk: boolean;
    /** Kilograms since the assessment before; null with fewer than two. */
    weightChange: number | null;
    nextReviewOn: string | null;
    reviewDue: boolean;
    needsAttention: boolean;
}

export interface NutritionCounts {
    total: number;
    assessed: number;
    notAssessed: number;
    needsAttention: number;
    reviewDue: number;
    waistAtRisk: number;
    byStatus: Record<NutritionStatusBadge['value'], number>;
}

export interface NutritionOverview extends NutritionCounts {
    attention: NutritionRow[];
}
