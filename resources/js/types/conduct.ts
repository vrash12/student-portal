import type { StatusTone } from '@/components/ui/status-badge';
import type { StatusValue } from '@/types/grading';

/**
 * Merits and demerits (ConductLedger on the server). Totals are sums of
 * points over entries that are not voided, calculated by the server; the
 * browser only displays them.
 */

export type ConductKindValue = 'merit' | 'demerit';

/** The kind of an entry or type, shown as text with its tone (never colour alone). */
export interface ConductKindStatus extends StatusValue {
    value: ConductKindValue;
}

/** A kind with the heading used to group types, e.g. "Merits". */
export interface ConductKindGroup {
    value: ConductKindValue;
    label: string;
    pluralLabel: string;
    tone: StatusTone;
}

/** A kind as a choice on the type form. */
export interface ConductKindOption {
    value: ConductKindValue;
    label: string;
    description: string;
}

/** Merit, demerit and net points (merits − demerits) over standing entries. */
export interface ConductTotals {
    merits: number;
    demerits: number;
    net: number;
}

export interface ConductEntryVoid {
    at: string;
    by: string | null;
    reason: string | null;
}

/** One line of a candidate's conduct ledger, newest first. */
export interface ConductEntryRow {
    id: number;
    /** Calendar date (Y-m-d). */
    occurredOn: string;
    kind: ConductKindStatus;
    /** Name of the merit/demerit type. */
    type: string;
    points: number;
    reason: string;
    recordedBy: string;
    recordedAt: string | null;
    /** Voided entries stay listed but do not count toward the totals. */
    voided: ConductEntryVoid | null;
}

/** An active type offered when recording an entry. */
export interface ConductTypeOption {
    id: number;
    name: string;
    kind: ConductKindValue;
    defaultPoints: number;
    description: string | null;
}

/** A type on the configuration pages. */
export interface ConductTypeRow {
    id: number;
    name: string;
    kind: ConductKindStatus;
    defaultPoints: number;
    description: string | null;
    sortOrder: number;
    isActive: boolean;
    entryCount: number;
}

/** A row of the scoped candidate list. */
export interface ConductCandidateRow {
    id: number;
    candidateNumber: string;
    name: string;
    className: string | null;
    /** Enrollment status of the candidate record. */
    status: StatusValue;
    totals: ConductTotals;
}

/** Who the conduct list covers: every candidate, the classes taught, or nobody. */
export type ConductScopeKind = 'all' | 'taught' | 'none';
