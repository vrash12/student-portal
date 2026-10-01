import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { Panel } from '@/components/ui/panel';
import { useMoney } from '@/lib/money';

/** A chart computed by the server for a list page (App\Support\ListCharts). */
export interface ListChart {
    kind: 'bars' | 'columns';
    title: string;
    description: string;
    items: Array<{ label: string; value: number | string }>;
    noun: { one: string; other: string };
    /** "money": values are two-decimal amounts; otherwise whole counts. */
    format: 'money' | null;
}

/**
 * The charts of a list page in one panel above the table. Charts with no data
 * are left out; nothing is shown when none has data. Every bar also shows its
 * value as text (never colour alone).
 */
export function ListCharts({ charts, title = 'At a Glance' }: { charts: ListChart[]; title?: string }) {
    const shown = charts.filter((chart) => chart.items.some((item) => Number(item.value) > 0));
    if (shown.length === 0) {
        return null;
    }

    return (
        <Panel
            title={title}
            description="Figures from the records shown below, with the current filters."
            className="mb-6"
        >
            <div className={shown.length === 1 ? 'grid gap-6' : 'grid gap-x-8 gap-y-6 lg:grid-cols-2'}>
                {shown.map((chart) => (
                    <ChartFigure key={chart.title} title={chart.title} description={chart.description}>
                        {chart.kind === 'columns' ? (
                            <ColumnChart columns={chart.items.map((item) => ({ label: item.label, value: Number(item.value) }))} noun={chart.noun} />
                        ) : (
                            <CountBars chart={chart} />
                        )}
                    </ChartFigure>
                ))}
            </div>
        </Panel>
    );
}

/** Horizontal bars scaled to the largest value, each with its value printed. */
function CountBars({ chart }: { chart: ListChart }) {
    const money = useMoney();
    const highest = Math.max(...chart.items.map((item) => Number(item.value)), 1);
    const format = (value: number | string) => (chart.format === 'money' ? money(String(value)) : String(value));

    return (
        <ul className="flex flex-col gap-2.5">
            {chart.items.map((item, index) => {
                const value = Number(item.value);

                return (
                    <li key={`${item.label}-${index}`} className="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_auto] items-center gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto]">
                        <span className="truncate text-sm text-ink" title={item.label}>
                            {item.label}
                        </span>
                        <span aria-hidden="true" className="h-3 rounded-full bg-chart-track">
                            <span
                                className="block h-full rounded-full bg-gradient-to-r from-primary-800 to-chart-bar"
                                style={{ width: `${Math.max(value > 0 ? 3 : 0, (value / highest) * 100)}%` }}
                            />
                        </span>
                        <span className="text-right text-sm font-semibold text-ink tabular-nums">
                            {format(item.value)}
                            {chart.format !== 'money' && <span className="sr-only"> {value === 1 ? chart.noun.one : chart.noun.other}</span>}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}
