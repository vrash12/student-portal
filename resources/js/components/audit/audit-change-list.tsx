import type { ReactNode } from 'react';

const EMPTY_VALUE = '—';

export type AuditValues = Record<string, unknown> | unknown[] | null;

interface AuditChangeListProps {
    before: AuditValues;
    after: AuditValues;
    /** Accessible name for the table, e.g. which entry it belongs to. */
    caption: string;
}

/**
 * Readable previous/new values for one audit entry (UI_UX_DESIGN.md §75):
 * one row per recorded field instead of raw JSON.
 */
export function AuditChangeList({ before, after, caption }: AuditChangeListProps) {
    const previous = asRecord(before);
    const next = asRecord(after);
    const fields = [...new Set([...Object.keys(previous), ...Object.keys(next)])];

    if (fields.length === 0) {
        return <p className="text-sm text-ink-muted">No field values were recorded for this action.</p>;
    }

    return (
        <table className="w-full text-left text-sm">
            <caption className="sr-only">{caption}</caption>
            <thead className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                <tr>
                    <th scope="col" className="w-1/4 py-2 pr-3">
                        Field
                    </th>
                    <th scope="col" className="py-2 pr-3">
                        Previous
                    </th>
                    <th scope="col" className="py-2">
                        New
                    </th>
                </tr>
            </thead>
            <tbody className="divide-y divide-line">
                {fields.map((field) => (
                    <tr key={field} className="align-top">
                        <th scope="row" className="py-2 pr-3 font-medium text-ink">
                            {humanizeKey(field)}
                        </th>
                        <td className="break-words py-2 pr-3 text-ink-muted">
                            {field in previous ? <AuditValue value={previous[field]} /> : EMPTY_VALUE}
                        </td>
                        <td className="break-words py-2 text-ink">{field in next ? <AuditValue value={next[field]} /> : EMPTY_VALUE}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** Top-level values are keyed by field; a bare list is shown under numbered fields. */
function asRecord(values: AuditValues): Record<string, unknown> {
    if (values === null) {
        return {};
    }

    return Array.isArray(values) ? Object.fromEntries(values.map((value, index) => [`Item ${index + 1}`, value])) : values;
}

/**
 * Renders any recorded value: null as a dash, booleans as Yes/No, lists of
 * plain values joined, and objects as "key: value" lines (nested as needed).
 */
function AuditValue({ value }: { value: unknown }): ReactNode {
    if (value === null || value === undefined || value === '') {
        return EMPTY_VALUE;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'number' || typeof value === 'string' || typeof value === 'bigint') {
        return String(value);
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return 'None';
        }

        if (value.every(isPlainValue)) {
            return value.map((item) => formatPlainValue(item)).join(', ');
        }

        return (
            <ul className="space-y-1.5">
                {value.map((item, index) => (
                    <li key={index} className="border-l-2 border-line pl-2">
                        <AuditValue value={item} />
                    </li>
                ))}
            </ul>
        );
    }

    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);

        if (entries.length === 0) {
            return 'None';
        }

        return (
            <ul className="space-y-0.5">
                {entries.map(([key, item]) => (
                    <li key={key}>
                        <span className="font-medium text-ink">{humanizeKey(key)}:</span> <AuditValue value={item} />
                    </li>
                ))}
            </ul>
        );
    }

    return EMPTY_VALUE;
}

function isPlainValue(value: unknown): boolean {
    return value === null || ['string', 'number', 'boolean', 'bigint'].includes(typeof value);
}

function formatPlainValue(value: unknown): string {
    if (value === null || value === '') {
        return EMPTY_VALUE;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}

/** "is_active" / "classBatchId" → "Is active" / "Class batch ID". */
export function humanizeKey(key: string): string {
    const words = key
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .trim()
        .toLowerCase()
        .replace(/id/g, 'ID');

    return words.charAt(0).toUpperCase() + words.slice(1);
}
