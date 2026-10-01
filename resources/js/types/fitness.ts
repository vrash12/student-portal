import type { StatusValue } from '@/types/grading';

/**
 * Military fitness (FitnessResults on the server). Points, pass/fail and
 * outcomes are calculated by the server; the browser only displays them.
 */

export type FitnessScoringMethod = 'table' | 'scaled';

/** One row of a points table: a result and the points it earns. */
export interface FitnessPointsRow {
    value: number;
    /** "42" or "12:30". */
    display: string;
    points: number;
}

/** An event as it was standardized for one test. */
export interface FitnessTestEvent {
    id: number;
    name: string;
    method: FitnessScoringMethod;
    unit: 'repetitions' | 'time';
    higherIsBetter: boolean;
    /** Points needed to pass the event. */
    passingPoints: number;
    /** Points of the best result: 100, or the top of the points table. */
    maximumPoints: number;
    /** The result that first reaches the passing points, and the one that first reaches the maximum points. */
    passingValue: number;
    maximumValue: number;
    /** "42" or "12:30". */
    passingDisplay: string;
    maximumDisplay: string;
    /** Rows from the weakest to the best result; empty for scaled standards. */
    table: FitnessPointsRow[];
}

export interface FitnessEventResult {
    value: number;
    display: string;
    points: number;
    passed: boolean;
}

export interface FitnessOutcome {
    status: StatusValue;
    /** Mean of the event points; null until every event has a result. */
    points: number | null;
}

export interface FitnessSheetRow {
    candidate: { id: number; candidateNumber: string; name: string; status: StatusValue };
    /** False for candidates who left the class or withdrew: read-only. */
    recordable: boolean;
    /** Keyed by test event id; null when not recorded. */
    results: Record<number, FitnessEventResult | null>;
    outcome: FitnessOutcome;
}

export interface FitnessSummary {
    counts: { passed: number; failed: number; incomplete: number; not_tested: number };
    meanPoints: number | null;
    /** Overall points of complete results by range, lowest first. */
    pointsDistribution: Array<{ label: string; value: number; muted?: boolean }>;
    /** Percentage of recorded results meeting each event's passing standard. */
    eventPassRates: Array<{ label: string; value: number | null; recorded: number; passed: number }>;
}

/** One test on a candidate's fitness history. */
export interface CandidateFitnessTest {
    id: number;
    title: string;
    testedOn: string;
    classBatch: string;
    events: Array<FitnessTestEvent & { result: FitnessEventResult | null }>;
    outcome: FitnessOutcome;
}
