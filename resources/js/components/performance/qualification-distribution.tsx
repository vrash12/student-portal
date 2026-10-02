import { PieChart } from '@/components/charts/pie-chart';
import type { ChartTone } from '@/types/charts';
import type { QualificationCounts } from '@/types/performance';

/** Statuses in the order shown, with their chart colors. Every slice is also given in text, never by colour alone (§47). */
const SERIES: ReadonlyArray<{ key: 'qualified' | 'pending' | 'notQualified'; label: string; tone: ChartTone }> = [
    { key: 'qualified', label: 'Qualified', tone: 'passing' },
    { key: 'pending', label: 'Pending', tone: 'atRisk' },
    { key: 'notQualified', label: 'Not Qualified', tone: 'failing' },
];

/**
 * Candidates per qualification status as a ring with a legend giving each
 * status's count and share. Counts come from the server.
 */
export function QualificationDistribution({ counts }: { counts: QualificationCounts }) {
    return (
        <PieChart
            slices={SERIES.map((series) => ({ label: series.label, value: counts[series.key], tone: series.tone }))}
            noun={{ one: 'candidate', other: 'candidates' }}
            listEmpty
        />
    );
}
