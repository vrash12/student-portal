import type { StandingTally } from '@/types/charts';

/**
 * A campus's figures for the active academic year (CampusAnalytics on the
 * server). Every figure is calculated by the server; the pages only show them.
 */
export interface CampusFigures {
    staffCount: number;
    instructorCount: number;
    classCount: number;
    candidateCount: number;
    /** Overall academic standing of the candidates (grade engine). */
    standing: StandingTally;
    /** Null without an active academic year. */
    qualification: {
        configured: boolean;
        counts: { total: number; qualified: number; notQualified: number; pending: number };
        mostCommonUnmet: { areaId: number; name: string; count: number } | null;
    } | null;
    /** (Present + Late) ÷ (Present + Late + Absent); null without records. */
    attendanceRate: number | null;
    attendanceRecords: number;
    conduct: { merits: number; demerits: number; net: number };
    fitness: { tests: number; passed: number; failed: number; incomplete: number; recorded: number; passRate: number | null };
    examinations: { published: number; submitted: number; meanScore: number | null };
}

export interface CampusClassFigures {
    id: number;
    name: string;
    candidateCount: number;
    standing: StandingTally;
    attendanceRate: number | null;
}

export interface CampusDetails extends CampusFigures {
    classes: CampusClassFigures[];
    attendanceTotals: { present: number; late: number; excused: number; absent: number };
    gradeDistribution: Array<{ label: string; value: number; muted?: boolean }>;
    subjectPerformance: Array<{ id: number; subject: string; average: number | null; classId: number; classBatch: string; subjectId: number }>;
    thresholds: { passingGrade: number; warningGrade: number } | null;
    instructors: Array<{ id: number; name: string; subjects: unknown[]; classes: unknown[] }>;
}
