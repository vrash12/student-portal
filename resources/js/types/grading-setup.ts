import type { GradingThresholds, StatusValue, TrainingPhaseSummary } from '@/types/grading';

/** One component of a subject's weights, e.g. Quizzes 20 (percent). */
export interface WeightComponent {
    name: string;
    /** Compact display form, e.g. "20" or "12.5". */
    weight: string;
}

/** One subject of one class in the selected period, with its weights. */
export interface SubjectWeightsRow {
    classSubjectId: number;
    classBatch: { id: number; name: string };
    subject: { id: number; code: string; name: string };
    /** The training phase the subject is in; null when not placed in one. */
    phase: TrainingPhaseSummary | null;
    /** The subject's weight in phase averages and the CGPA, display form. */
    units: string;
    /** Empty when the subject has no weights yet (instructors cannot create assessments). */
    components: WeightComponent[];
    assessmentCount: number;
    finalizedCount: number;
}

/** A subject whose weights can be copied. */
export interface WeightsCopySource {
    classSubjectId: number;
    /** "Class A · Subject 1 (First Semester 2026-2027)" */
    label: string;
    components: WeightComponent[];
}

export interface AreaSummary {
    id: number;
    name: string;
    source: { value: 'subjects' | 'fitness' | 'conduct' | 'attendance'; label: string };
    weight: string;
    /** The share of the overall score the area really gets (weight ÷ total of the active weights), in percent. */
    share: number;
    passingGrade: string;
    mustPass: boolean;
    subjects: string[];
    conductRule: { base: string; merit: string; demerit: string } | null;
}

export interface AreasOverview {
    list: AreaSummary[];
    totalWeight: number;
    hasMustPass: boolean;
    /** Active subject areas without any subject: always "No results yet". */
    emptySubjectAreas: string[];
    /** Subjects taught in the period whose grades count toward no active area. */
    unmappedSubjects: string[];
}

/** How one subject grade is worked out, calculated by the server's grade engine with sample results. */
export interface WorkedExample {
    /** The subject whose weights are used, or null for a generic example. */
    source: string | null;
    components: Array<{ name: string; weight: number; result: number | null; points: number | null }>;
    grade: number | null;
    standing: StatusValue | null;
    /** The same results while only the first components are assessed. */
    partial: { assessed: string[]; assessedWeight: number; grade: number | null } | null;
}

export interface GradingSetupProps {
    period: { id: number; name: string; isActive: boolean } | null;
    periods: Array<{ id: number; name: string; isActive: boolean }>;
    thresholds: GradingThresholds | null;
    subjectWeights: SubjectWeightsRow[];
    copySources: WeightsCopySource[];
    areas: AreasOverview;
    sources: { fitnessEvents: number; conductTypes: number };
    example: WorkedExample;
    can: {
        manageClasses: boolean;
        managePeriods: boolean;
        /** Passing and warning grades apply to every campus: set by accounts that see every campus. */
        setThresholds: boolean;
        configurePerformance: boolean;
        configureFitness: boolean;
        manageAttendance: boolean;
    };
}
