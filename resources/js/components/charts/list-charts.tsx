import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { LineChart } from '@/components/charts/line-chart';
import { PieChart } from '@/components/charts/pie-chart';
import { Panel } from '@/components/ui/panel';
import { cn } from '@/lib/cn';
import { useMoney } from '@/lib/money';
import type { ChartTone, LineChartData } from '@/types/charts';

interface ListChartBase {
    title: string;
    description: string;
    noun: { one: string; other: string };
}

/** A chart computed by the server for a list page (App\Support\ListCharts). */
export type ListChart =
    | (ListChartBase & {
          kind: 'bars' | 'columns';
          items: Array<{ label: string; value: number | string }>;
          /** "money": values are two-decimal amounts; otherwise whole counts. */
          format: 'money' | null;
      })
    | (ListChartBase & { kind: 'pie'; items: Array<{ label: string; value: number; tone?: ChartTone | null }>; format: null })
    | (ListChartBase & { kind: 'line'; data: LineChartData; xLabel: string; format: null });

function hasData(chart: ListChart): boolean {
    if (chart.kind === 'line') {
        return chart.data.xType === 'date'
            ? chart.data.series.some((line) => line.points.some((point) => point.value > 0))
            : chart.data.series.some((line) => line.values.some((value) => value !== null && value > 0));
    }

    const counted = chart.items.filter((item) => Number(item.value) > 0).length;

    return chart.kind === 'pie' ? counted >= 2 : counted > 0;
}

/**
 * The charts of a list page in one panel above the table. Charts with no data
 * are left out, and so are pies with a single category (a full ring says no
 * more than its label); nothing is shown when no chart is left. Every bar,
 * slice and point also shows its value as text (never colour alone).
 */
export function ListCharts({ charts, title = 'At a Glance' }: { charts: ListChart[]; title?: string }) {
    const shown = charts.filter(hasData);
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
                    <ChartFigure key={chart.title} title={chart.title} description={chart.description} className={cn(chart.kind === 'line' && shown.length > 2 && 'lg:col-span-2')}>
                        {chart.kind === 'columns' && <ColumnChart columns={chart.items.map((item) => ({ label: item.label, value: Number(item.value) }))} noun={chart.noun} />}
                        {chart.kind === 'bars' && <CountBars items={chart.items} noun={chart.noun} format={chart.format} />}
                        {chart.kind === 'pie' && <PieChart slices={chart.items} noun={chart.noun} />}
                        {chart.kind === 'line' && <LineChart data={chart.data} format="count" label={chart.title} xLabel={chart.xLabel} height={200} />}
                    </ChartFigure>
                ))}
            </div>
        </Panel>
    );
}

/** Horizontal bars scaled to the largest value, each with its value printed. */
function CountBars({ items, noun, format }: { items: Array<{ label: string; value: number | string }>; noun: { one: string; other: string }; format: 'money' | null }) {
    const money = useMoney();
    const highest = Math.max(...items.map((item) => Number(item.value)), 1);
    const formatValue = (value: number | string) => (format === 'money' ? money(String(value)) : String(value));

    return (
        <ul className="flex flex-col gap-2.5">
            {items.map((item, index) => {
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
                            {formatValue(item.value)}
                            {format !== 'money' && <span className="sr-only"> {value === 1 ? noun.one : noun.other}</span>}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}
