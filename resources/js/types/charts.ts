/**
 * Chart data prepared by the server. Charts only draw these figures; every
 * count, grade and percentage comes from the backend (AGENTS.md §15, §41).
 */

/** Candidates (or candidate-subject records) per standing. */
export interface StandingTally {
    passing: number;
    atRisk: number;
    failing: number;
    incomplete: number;
    noStanding: number;
}

/** One row of a standing breakdown, e.g. a class or a subject in a class. */
export interface StandingGroup {
    label: string;
    counts: StandingTally;
    /** Mean current grade of the group, shown beside its bar. */
    average?: number | null;
}

/** One horizontal bar on a 0–100 scale, e.g. a subject's mean grade. */
export interface ChartBar {
    label: string;
    value: number | null;
    /** This bar's own target on the same scale (e.g. each area's passing grade); see BarList markerLabel. */
    marker?: number | null;
}

/** A labelled marker on a 0–100 scale, e.g. the passing grade. */
export interface ChartReference {
    label: string;
    value: number;
}

/** One column of a distribution; `muted` marks a column outside the scale, e.g. "No grade". */
export interface ChartColumn {
    label: string;
    value: number;
    muted?: boolean;
}

interface ChartBase {
    title: string;
    description: string;
}

/** The charts a report can show above its table. */
export type ReportChart =
    | (ChartBase & { kind: 'standingTotal'; counts: StandingTally })
    | (ChartBase & { kind: 'standing'; groups: StandingGroup[] })
    | (ChartBase & { kind: 'bars'; bars: ChartBar[]; references: ChartReference[] })
    | (ChartBase & { kind: 'columns'; columns: ChartColumn[]; noun: { one: string; other: string } });
