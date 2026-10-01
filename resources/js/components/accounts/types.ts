import type { StatusValue } from '@/types/grading';

/**
 * Statement of Account data from the server. Amounts are two-decimal strings
 * calculated in whole cents by the server ("1250.50", "-80.00"); the browser
 * only formats them.
 */

export interface AccountEntryTypeOption {
    value: string;
    label: string;
    description: string;
}

/** A balance as text and tone: "Balance due", "Credit balance" or "Settled". */
export type BalanceStatus = StatusValue;

export interface AccountOverview {
    totalDue: string;
    candidatesDue: number;
    /** Total of credit balances, as a positive amount. */
    totalCredit: string;
    candidatesInCredit: number;
    recentEntries: Array<{
        id: number;
        candidate: { id: number; candidateNumber: string; name: string };
        category: string;
        type: { value: string; label: string };
        amount: string;
        postedOn: string;
    }>;
}

export interface StatementEntry {
    id: number;
    postedOn: string;
    category: string;
    type: { value: string; label: string };
    amount: string;
    description: string;
    reference: string | null;
    recordedBy: string;
    recordedAt: string | null;
    /** Running balance after this entry; null for voided entries, which do not count. */
    balance: string | null;
    voided: { at: string; by: string | null; reason: string } | null;
}

export interface Statement {
    from: string | null;
    to: string | null;
    /** Balance brought forward from before `from` (zero without a start date). */
    opening: string;
    charges: string;
    credits: string;
    closing: string;
    status: BalanceStatus;
    entries: StatementEntry[];
}

export interface AccountCategoryRow {
    id: number;
    name: string;
    entryType: { value: string; label: string };
    description: string | null;
    sortOrder: number;
    isActive: boolean;
    entryCount: number;
}
