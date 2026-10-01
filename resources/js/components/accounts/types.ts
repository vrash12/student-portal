/**
 * Expense data from the server. Amounts are two-decimal strings calculated
 * in whole cents by the server ("1250.50"); the browser only formats them.
 */

export interface AccountEntryTypeOption {
    value: string;
    label: string;
    description: string;
}

/** A charge entry as shown when voiding it. */
export interface ChargeEntry {
    id: number;
    postedOn: string;
    category: string;
    type: { value: string; label: string };
    description: string;
}

/** An expense defined once and assigned to candidates. */
export interface AccountExpense {
    id: number;
    name: string;
    category: { id: number; name: string };
    amount: string;
    dueOn: string | null;
    description: string | null;
    isActive: boolean;
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
