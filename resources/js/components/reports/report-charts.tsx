import { BarList } from '@/components/charts/bar-list';
import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { StandingBreakdown, StandingDistribution } from '@/components/charts/standing-breakdown';
import { terms } from '@/lib/terminology';
import type { ReportChart } from '@/types/charts';

const CANDIDATE_NOUN = { one: 'candidate', other: 'candidates' };

/** Uses the configured name for classes ("Class", "Batch", …) in chart titles from the server. */
function withClassTerm(text: string): string {
    return text.replace(/\bClass\b/g, terms.classBatch.singular).replace(/\bclass\b/g, terms.classBatch.singular.toLowerCase());
}

/**
 * The charts of a report, above its table. They summarize every row of the
 * filtered report (not only the current page); the table stays the full
 * record.
 */
export function ReportCharts({ charts }: { charts: ReportChart[] }) {
    if (charts.length === 0) {
        return null;
    }

    return (
        <div className={`grid gap-8 border-b border-line p-5 print:px-0 ${charts.length > 1 ? 'xl:grid-cols-2' : ''}`}>
            {charts.map((chart) => (
                <ChartFigure key={chart.title} title={withClassTerm(chart.title)} description={withClassTerm(chart.description)}>
                    {chart.kind === 'standingTotal' && <StandingDistribution counts={chart.counts} />}
                    {chart.kind === 'standing' && <StandingBreakdown groups={chart.groups} noun={CANDIDATE_NOUN} />}
                    {chart.kind === 'bars' && <BarList bars={chart.bars} references={chart.references} />}
                    {chart.kind === 'columns' && <ColumnChart columns={chart.columns} noun={chart.noun} />}
                </ChartFigure>
            ))}
        </div>
    );
}
