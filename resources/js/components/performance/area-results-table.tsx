import { AreaStatusBadge, formatAreaGrade, formatNumber } from '@/components/performance/area-status';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import type { AreaResultData, PerformanceAreaSummary } from '@/types/performance';

interface AreaResultsTableProps {
    /** The active areas, in area order. */
    areas: PerformanceAreaSummary[];
    /** One candidate's results, matched to the areas by `areaId`. */
    results: AreaResultData[];
    caption?: string;
}

/**
 * One candidate's result in each performance area: the rule (weight,
 * passing grade, must pass), the grade, and the status in text with the
 * server's explanation. Read-only; suitable for the candidate profile and
 * the candidate portal (it shows nothing about other candidates).
 */
export function AreaResultsTable({ areas, results, caption = 'Performance area results' }: AreaResultsTableProps) {
    const resultsByArea = new Map(results.map((result) => [result.areaId, result]));

    return (
        <Table caption={caption} className="min-w-[44rem]">
            <TableHead>
                <Th>Area</Th>
                <Th align="right">Weight</Th>
                <Th align="right">Passing Grade</Th>
                <Th>Must Pass</Th>
                <Th align="right">Grade</Th>
                <Th>Status</Th>
            </TableHead>
            <TableBody>
                {areas.map((area) => {
                    const result = resultsByArea.get(area.id);

                    return (
                        <Tr key={area.id}>
                            <Td className="text-ink">
                                <span className="font-medium">{area.name}</span>
                                <span className="block text-xs text-ink-muted">{area.source.label}</span>
                            </Td>
                            <Td align="right" numeric className="text-ink-muted">
                                {formatNumber(area.weight)}
                            </Td>
                            <Td align="right" numeric className="text-ink-muted">
                                {formatNumber(area.passingGrade)}
                            </Td>
                            <Td className="text-ink-muted">{area.mustPass ? 'Yes' : 'No'}</Td>
                            <Td align="right" numeric className="font-semibold text-ink">
                                {formatAreaGrade(result?.grade ?? null)}
                            </Td>
                            <Td>
                                {result === undefined ? (
                                    <span className="text-ink-muted">Not calculated</span>
                                ) : (
                                    <div className="flex max-w-xs flex-col items-start gap-1">
                                        <AreaStatusBadge status={result.status} />
                                        {result.note && <span className="text-xs text-ink-muted">{result.note}</span>}
                                    </div>
                                )}
                            </Td>
                        </Tr>
                    );
                })}
            </TableBody>
        </Table>
    );
}
