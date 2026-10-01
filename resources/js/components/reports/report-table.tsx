import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';

type ReportRow = Record<string, string | number | null>;

interface ReportTableProps {
    caption: string;
    /** Column key → heading, in display order. */
    columns: Record<string, string>;
    /** Keys of columns holding numbers; they are right-aligned with tabular figures (UI_UX_DESIGN.md §31). */
    numericColumns: string[];
    rows: ReportRow[];
}

/**
 * Report rows exactly as the server produced them. The wrapper class lets the
 * print stylesheet drop the horizontal scroll area so no column is clipped.
 */
export function ReportTable({ caption, columns, numericColumns, rows }: ReportTableProps) {
    const numeric = new Set(numericColumns);
    const columnEntries = Object.entries(columns);

    return (
        <div className="report-print-table">
            <Table caption={caption}>
                <TableHead>
                    {columnEntries.map(([key, label]) => (
                        <Th key={key} align={numeric.has(key) ? 'right' : 'left'}>
                            {label}
                        </Th>
                    ))}
                </TableHead>
                <TableBody>
                    {rows.map((row, index) => (
                        <Tr key={index}>
                            {columnEntries.map(([key]) => (
                                <Td
                                    key={key}
                                    align={numeric.has(key) ? 'right' : 'left'}
                                    numeric={numeric.has(key)}
                                    className={numeric.has(key) ? 'whitespace-nowrap align-top text-ink' : 'align-top text-ink'}
                                >
                                    {row[key] ?? '—'}
                                </Td>
                            ))}
                        </Tr>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
