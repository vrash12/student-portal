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

/**
 * A chart color: a status meaning (standings, attendance, outcomes) or a
 * plain category color c1–c6 (roles, types, subjects).
 */
export type ChartTone = 'passing' | 'atRisk' | 'failing' | 'incomplete' | 'none' | 'c1' | 'c2' | 'c3' | 'c4' | 'c5' | 'c6';

/** One slice of a pie: a share of a whole, e.g. candidates with one standing. */
export interface PieSlice {
    label: string;
    value: number;
    /** Null: the next category color. */
    tone?: ChartTone | null;
}

/** How a chart prints its values: "84.50%", "84.50" (a grade) or a whole count. */
export type ChartValueFormat = 'percent' | 'grade' | 'count';

/**
 * Values over time or over an ordered list, drawn as lines:
 * - "category": one value per category, in order (e.g. each examination);
 * - "date": points on calendar dates (YYYY-MM-DD) on a time scale.
 */
export type LineChartData =
    | {
          xType: 'category';
          categories: Array<{ label: string; detail?: string | null }>;
          series: Array<{ label: string; tone?: ChartTone | null; values: Array<number | null> }>;
      }
    | {
          xType: 'date';
          series: Array<{ label: string; tone?: ChartTone | null; points: Array<{ date: string; value: number; detail?: string | null }> }>;
      };

interface ChartBase {
    title: string;
    description: string;
    /** Takes the full width beside other charts (lines over time). */
    wide?: boolean;
}

/** The charts a report can show above its table. */
export type ReportChart =
    | (ChartBase & { kind: 'standingTotal'; counts: StandingTally })
    | (ChartBase & { kind: 'standing'; groups: StandingGroup[] })
    | (ChartBase & { kind: 'bars'; bars: ChartBar[]; references: ChartReference[] })
    | (ChartBase & { kind: 'columns'; columns: ChartColumn[]; noun: { one: string; other: string } })
    | (ChartBase & { kind: 'pie'; slices: PieSlice[]; noun: { one: string; other: string } })
    | (ChartBase & { kind: 'line'; data: LineChartData; format: ChartValueFormat; references: ChartReference[]; xLabel: string });
