import { useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent, type RefObject } from 'react';
import { markerAt, markerPath, toneAt, toneClasses, type MarkerShape } from '@/components/charts/chart-colors';
import { cn } from '@/lib/cn';
import { formatCalendarDate, formatGrade, formatPercent } from '@/lib/format';
import type { ChartReference, ChartTone, ChartValueFormat, LineChartData } from '@/types/charts';

interface LineChartProps {
    data: LineChartData;
    format?: ChartValueFormat;
    /** Top of the value scale: 100 for grades and percentages. Left out, the scale fits the data (counts). */
    yMax?: number;
    /** Horizontal markers such as the passing grade (solid) and warning grade (dashed). */
    references?: ChartReference[];
    /** The chart's accessible name; usually the figure title. */
    label: string;
    /** Heading of the first column of the screen-reader table, e.g. "Date" or "Examination". */
    xLabel: string;
    height?: number;
}

interface AxisKey {
    /** Full text: the category, or the date written out. */
    label: string;
    /** Short text under the axis. */
    axisLabel: string;
    detail: string | null;
    /** The category's place, or the date in milliseconds. */
    position: number;
}

interface PlotSeries {
    label: string;
    tone: ChartTone;
    shape: MarkerShape;
    /** By key; consecutive keys only are joined, so a missing value breaks the line. */
    points: Array<{ key: number; value: number; detail: string | null }>;
    joinAcrossGaps: boolean;
}

const MARGIN = { top: 28, right: 16, bottom: 30 };
/** Space inside the plot so the first and last points and their labels stay clear of the edges. */
const EDGE = 16;
/** Least distance between labels under the axis, in pixels. */
const AXIS_LABEL_GAP = 60;
/** Value labels closer than this (across, and up or down) would overlap, in pixels. */
const VALUE_LABEL_GAP = { across: 40, down: 14 };
const REFERENCE_DASHES = [undefined, '6 4'] as const;

const shortDate = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });

const AXIS_FORMAT: Record<ChartValueFormat, (value: number) => string> = {
    percent: (value) => `${trimNumber(value)}%`,
    grade: (value) => trimNumber(value),
    count: (value) => trimNumber(value),
};

/** Values in the tooltip and the screen-reader table: as the rest of the system prints them. */
const VALUE_FORMAT: Record<ChartValueFormat, (value: number) => string> = {
    percent: (value) => formatPercent(value),
    grade: (value) => formatGrade(value),
    count: (value) => String(value),
};

/** Values printed beside the points: shorter, one decimal at most. */
const POINT_FORMAT: Record<ChartValueFormat, (value: number) => string> = {
    percent: (value) => `${trimNumber(Math.round(value * 10) / 10)}%`,
    grade: (value) => trimNumber(Math.round(value * 10) / 10),
    count: (value) => String(value),
};

function trimNumber(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function truncate(text: string, characters: number): string {
    return text.length <= characters ? text : `${text.slice(0, Math.max(1, characters - 1))}…`;
}

/** A rounded step for about four gridlines up to `highest`; whole steps for counts. */
function scaleFor(highest: number, format: ChartValueFormat, fixedTop?: number): { top: number; ticks: number[] } {
    if (fixedTop !== undefined) {
        const step = fixedTop / 4;

        return { top: fixedTop, ticks: [0, step, step * 2, step * 3, fixedTop] };
    }

    const raw = Math.max(highest, 1) / 4;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalized = raw / magnitude;
    let step = (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 2.5 ? 2.5 : normalized <= 5 ? 5 : 10) * magnitude;
    if (format === 'count') {
        step = Math.max(1, Math.ceil(step));
    }
    const top = Math.max(step, Math.ceil(Math.max(highest, 1) / step) * step);
    const ticks: number[] = [];
    for (let tick = 0; tick <= top + step / 2; tick += step) {
        ticks.push(Math.round(tick * 100) / 100);
    }

    return { top, ticks };
}

function normalize(data: LineChartData): { keys: AxisKey[]; series: PlotSeries[] } {
    if (data.xType === 'category') {
        const keys = data.categories.map((category, index) => ({
            label: category.label,
            axisLabel: category.label,
            detail: category.detail ?? null,
            position: index,
        }));

        return {
            keys,
            series: data.series.map((line, index) => ({
                label: line.label,
                tone: toneAt(index, line.tone),
                shape: markerAt(index),
                points: line.values.flatMap((value, key) => (value === null || key >= keys.length ? [] : [{ key, value, detail: null }])),
                joinAcrossGaps: false,
            })),
        };
    }

    const dates = [...new Set(data.series.flatMap((line) => line.points.map((point) => point.date)))].sort();
    const keyOf = new Map(dates.map((date, index) => [date, index]));

    return {
        keys: dates.map((date) => {
            const time = Date.parse(`${date}T00:00:00Z`);

            return { label: formatCalendarDate(date), axisLabel: shortDate.format(time), detail: null, position: time };
        }),
        series: data.series.map((line, index) => ({
            label: line.label,
            tone: toneAt(index, line.tone),
            shape: markerAt(index),
            points: line.points
                .map((point) => ({ key: keyOf.get(point.date) ?? 0, value: point.value, detail: point.detail ?? null }))
                .sort((first, second) => first.key - second.key),
            joinAcrossGaps: true,
        })),
    };
}

/** The element's width in pixels, kept current as the layout changes. */
function useWidth(): [RefObject<HTMLDivElement | null>, number] {
    const ref = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);

    useLayoutEffect(() => {
        const element = ref.current;
        if (element === null) {
            return;
        }
        const measure = () => setWidth(Math.floor(element.getBoundingClientRect().width));
        measure();
        if (typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width];
}

/**
 * Values over time or over an ordered list (performance over time,
 * UI_UX_DESIGN.md §61) as SVG lines: no chart library, works offline,
 * prints in color. Lines differ by color and marker shape; values show on
 * hover, on tap, and with the arrow keys, and screen readers get the same
 * figures as a table. Every value comes from the server.
 */
export function LineChart({ data, format = 'percent', yMax, references = [], label, xLabel, height = 240 }: LineChartProps) {
    const [containerRef, width] = useWidth();
    const plotRef = useRef<SVGSVGElement>(null);
    const [active, setActive] = useState<number | null>(null);
    const { keys, series } = useMemo(() => normalize(data), [data]);
    const markers = references.slice(0, REFERENCE_DASHES.length);
    // A single line gets a light fill under it.
    const onlyLine = series.length === 1 ? series[0] : undefined;

    const values = series.flatMap((line) => line.points.map((point) => point.value));
    const hasValues = values.length > 0;
    const { top, ticks } = scaleFor(Math.max(0, ...values, ...markers.map((reference) => reference.value)), format, yMax);
    const chartHeight = width > 0 && width < 420 ? Math.min(height, 210) : height;

    const tickLabels = ticks.map((tick) => AXIS_FORMAT[format](tick));
    const plotLeft = 12 + Math.max(...tickLabels.map((tick) => tick.length)) * 7;
    const plotRight = Math.max(plotLeft + 40, width - MARGIN.right);
    const plotTop = MARGIN.top;
    const plotBottom = chartHeight - MARGIN.bottom;
    const firstPosition = keys[0]?.position ?? 0;
    const lastPosition = keys[keys.length - 1]?.position ?? 0;

    const xOf = (key: number): number => {
        const span = lastPosition - firstPosition;
        if (keys.length <= 1 || span === 0) {
            return (plotLeft + plotRight) / 2;
        }

        return plotLeft + EDGE + (((keys[key]?.position ?? firstPosition) - firstPosition) / span) * (plotRight - plotLeft - EDGE * 2);
    };
    const yOf = (value: number): number => plotBottom - (Math.min(Math.max(value, 0), top) / top) * (plotBottom - plotTop);

    const lastKey = keys.length - 1;
    const axisKeys: number[] = [];
    keys.forEach((_, key) => {
        const previous = axisKeys[axisKeys.length - 1];
        if (previous === undefined || xOf(key) - xOf(previous) >= AXIS_LABEL_GAP) {
            axisKeys.push(key);
        }
    });
    if (lastKey > 0 && axisKeys[axisKeys.length - 1] !== lastKey) {
        axisKeys.pop();
        axisKeys.push(lastKey);
    }
    // As many characters as the space between labels allows (about 6.5 px each).
    const axisSpacing = axisKeys.length > 1 ? (plotRight - plotLeft - EDGE * 2) / (axisKeys.length - 1) : plotRight - plotLeft;
    const axisCharacters = Math.min(40, Math.max(5, Math.floor((axisSpacing - 8) / 6.5)));
    // Printed values while nothing is selected (lines of up to two series).
    const valueLabels = series.length > 2 ? [] : placeValueLabels(series, xOf, yOf, plotBottom, POINT_FORMAT[format]);

    const nearestKey = (clientX: number): number | null => {
        const box = plotRef.current?.getBoundingClientRect();
        if (box === undefined || box.width === 0 || keys.length === 0) {
            return null;
        }
        const x = ((clientX - box.left) / box.width) * width;
        let nearest = 0;
        keys.forEach((_, key) => {
            if (Math.abs(xOf(key) - x) < Math.abs(xOf(nearest) - x)) {
                nearest = key;
            }
        });

        return nearest;
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const moves: Record<string, (current: number) => number> = {
            ArrowRight: (current) => Math.min(lastKey, current + 1),
            ArrowLeft: (current) => Math.max(0, current - 1),
            Home: () => 0,
            End: () => lastKey,
        };
        const move = moves[event.key];
        if (move !== undefined) {
            event.preventDefault();
            setActive((current) => move(current ?? lastKey));
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    const activeKey = active === null || active > lastKey ? null : active;
    const activeX = activeKey === null ? 0 : xOf(activeKey);
    const activeEntries =
        activeKey === null
            ? []
            : series.flatMap((line) => line.points.filter((point) => point.key === activeKey).map((point) => ({ line, point })));
    const activeText =
        activeKey === null
            ? ''
            : `${keys[activeKey]?.label ?? ''}: ${
                  activeEntries.length === 0
                      ? 'no value'
                      : activeEntries.map(({ line, point }) => `${series.length > 1 ? `${line.label} ` : ''}${VALUE_FORMAT[format](point.value)}`).join(', ')
              }`;

    return (
        <div ref={containerRef} className="flex min-w-0 flex-col gap-3 [-webkit-print-color-adjust:exact] [print-color-adjust:exact]">
            {!hasValues ? (
                <p className="text-sm text-ink-muted">No values to show yet.</p>
            ) : (
                width > 0 && (
                    <div
                        tabIndex={0}
                        role="group"
                        aria-label={`${label}. Use the left and right arrow keys to read each value.`}
                        className="relative rounded-md outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                        onKeyDown={onKeyDown}
                        onFocus={() => setActive((current) => current ?? lastKey)}
                        onBlur={() => setActive(null)}
                    >
                        <svg
                            ref={plotRef}
                            viewBox={`0 0 ${width} ${chartHeight}`}
                            className="block h-auto w-full touch-pan-y select-none"
                            aria-hidden="true"
                            onPointerMove={(event: PointerEvent<SVGSVGElement>) => setActive(nearestKey(event.clientX))}
                            onPointerDown={(event: PointerEvent<SVGSVGElement>) => setActive(nearestKey(event.clientX))}
                            onPointerLeave={(event: PointerEvent<SVGSVGElement>) => {
                                if (event.pointerType === 'mouse') {
                                    setActive(null);
                                }
                            }}
                        >
                            {ticks.map((tick, index) => (
                                <g key={tick}>
                                    <line
                                        x1={plotLeft}
                                        x2={plotRight}
                                        y1={yOf(tick)}
                                        y2={yOf(tick)}
                                        className={index === 0 ? 'stroke-line-strong' : 'stroke-line'}
                                        strokeWidth={1}
                                        strokeDasharray={index === 0 ? undefined : '2 4'}
                                    />
                                    <text x={plotLeft - 8} y={yOf(tick)} dy="0.32em" textAnchor="end" className="fill-ink-muted text-[11px] tabular-nums">
                                        {tickLabels[index]}
                                    </text>
                                </g>
                            ))}

                            {markers.map((reference, index) => (
                                <g key={reference.label}>
                                    <line
                                        x1={plotLeft}
                                        x2={plotRight}
                                        y1={yOf(reference.value)}
                                        y2={yOf(reference.value)}
                                        className="stroke-ink"
                                        strokeWidth={1.5}
                                        strokeDasharray={REFERENCE_DASHES[index]}
                                    />
                                    <text x={plotRight} y={yOf(reference.value) - 5} textAnchor="end" className="fill-ink text-[11px] font-medium">
                                        {reference.label} {AXIS_FORMAT[format](reference.value)}
                                    </text>
                                </g>
                            ))}

                            {onlyLine !== undefined &&
                                segmentsOf(onlyLine).map((segment, index) => {
                                    const first = segment[0];
                                    const last = segment[segment.length - 1];

                                    return first === undefined || last === undefined || segment.length < 2 ? null : (
                                        <path
                                            key={`area-${index}`}
                                            d={`M${xOf(first.key)} ${plotBottom}${segment.map((point) => `L${xOf(point.key)} ${yOf(point.value)}`).join('')}L${xOf(last.key)} ${plotBottom}Z`}
                                            className={toneClasses(onlyLine.tone).fillSoft}
                                        />
                                    );
                                })}

                            {activeKey !== null && (
                                <line x1={activeX} x2={activeX} y1={plotTop} y2={plotBottom} className="stroke-ink-subtle" strokeWidth={1} strokeDasharray="3 3" />
                            )}

                            {series.map((line, seriesIndex) => (
                                <g key={`${line.label}-${seriesIndex}`}>
                                    {segmentsOf(line).map((segment, index) => (
                                        <path
                                            key={index}
                                            d={segment.map((point, pointIndex) => `${pointIndex === 0 ? 'M' : 'L'}${xOf(point.key)} ${yOf(point.value)}`).join('')}
                                            fill="none"
                                            strokeWidth={2.5}
                                            strokeLinejoin="round"
                                            strokeLinecap="round"
                                            className={toneClasses(line.tone).stroke}
                                        />
                                    ))}
                                    {line.points.map((point) => (
                                        <path
                                            key={point.key}
                                            d={markerPath(line.shape, xOf(point.key), yOf(point.value), point.key === activeKey ? 5 : 3.6)}
                                            className={cn(toneClasses(line.tone).fill, 'stroke-white')}
                                            strokeWidth={1.5}
                                        />
                                    ))}
                                </g>
                            ))}

                            {activeKey === null &&
                                valueLabels.map((valueLabel) => (
                                    <text
                                        key={valueLabel.key}
                                        x={valueLabel.x}
                                        y={valueLabel.y}
                                        textAnchor="middle"
                                        className="fill-ink stroke-white text-[11px] font-semibold tabular-nums [paint-order:stroke]"
                                        strokeWidth={3}
                                    >
                                        {valueLabel.text}
                                    </text>
                                ))}

                            {axisKeys.map((key) => {
                                const x = xOf(key);
                                const anchor = keys.length > 1 && key === 0 ? 'start' : keys.length > 1 && key === lastKey ? 'end' : 'middle';

                                return (
                                    <text
                                        key={key}
                                        x={anchor === 'start' ? x - EDGE + 2 : anchor === 'end' ? x + EDGE - 2 : x}
                                        y={plotBottom + 19}
                                        textAnchor={anchor}
                                        className="fill-ink-muted text-[11px]"
                                    >
                                        {truncate(keys[key]?.axisLabel ?? '', axisCharacters)}
                                    </text>
                                );
                            })}
                        </svg>

                        {activeKey !== null && (
                            <div
                                aria-hidden="true"
                                className="pointer-events-none absolute z-10 w-max max-w-[16rem] rounded-md border border-line-box bg-surface px-3 py-2 text-xs shadow-md print:hidden"
                                style={{
                                    top: plotTop,
                                    ...(activeX > width / 2 ? { right: width - activeX + 10 } : { left: activeX + 10 }),
                                }}
                            >
                                <p className="font-semibold text-ink">{keys[activeKey]?.label}</p>
                                {keys[activeKey]?.detail && <p className="text-ink-muted">{keys[activeKey]?.detail}</p>}
                                <ul className="mt-1 flex flex-col gap-0.5">
                                    {activeEntries.length === 0 && <li className="text-ink-muted">No value</li>}
                                    {activeEntries.map(({ line, point }, index) => (
                                        <li key={`${line.label}-${index}`} className="flex items-center gap-2 text-ink">
                                            <MarkerSwatch tone={line.tone} shape={line.shape} />
                                            {series.length > 1 && <span>{line.label}</span>}
                                            <span className="font-semibold tabular-nums">{VALUE_FORMAT[format](point.value)}</span>
                                            {point.detail && <span className="text-ink-muted">· {point.detail}</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <p className="sr-only" aria-live="polite">
                            {activeText}
                        </p>
                    </div>
                )
            )}

            {hasValues && (series.length > 1 || markers.length > 0) && (
                <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-ink">
                    {series.length > 1 &&
                        series.map((line, index) => (
                            <li key={`${line.label}-${index}`} className="flex items-center gap-2">
                                <MarkerSwatch tone={line.tone} shape={line.shape} withLine />
                                {line.label}
                            </li>
                        ))}
                    {markers.map((reference, index) => (
                        <li key={reference.label} className="flex items-center gap-2">
                            <svg aria-hidden="true" viewBox="0 0 20 10" className="h-2.5 w-5">
                                <line x1="0" x2="20" y1="5" y2="5" className="stroke-ink" strokeWidth={2} strokeDasharray={REFERENCE_DASHES[index] === undefined ? undefined : '4 3'} />
                            </svg>
                            {reference.label} <span className="tabular-nums">{VALUE_FORMAT[format](reference.value)}</span>
                        </li>
                    ))}
                </ul>
            )}

            {hasValues && (
                <table className="sr-only">
                    <caption>{label}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{xLabel}</th>
                            {series.map((line, index) => (
                                <th key={`${line.label}-${index}`} scope="col">
                                    {line.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {keys.map((key, index) => (
                            <tr key={`${key.label}-${index}`}>
                                <th scope="row">
                                    {key.label}
                                    {key.detail ? ` (${key.detail})` : ''}
                                </th>
                                {series.map((line, seriesIndex) => {
                                    const points = line.points.filter((candidate) => candidate.key === index);

                                    return (
                                        <td key={`${line.label}-${seriesIndex}`}>
                                            {points.length === 0
                                                ? 'No value'
                                                : points.map((point) => `${VALUE_FORMAT[format](point.value)}${point.detail ? ` (${point.detail})` : ''}`).join(', ')}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

/** The runs of a line to draw: consecutive keys only, unless the line may join across gaps (dates). */
function segmentsOf(line: PlotSeries): Array<PlotSeries['points']> {
    const segments: Array<PlotSeries['points']> = [];
    for (const point of line.points) {
        const current = segments[segments.length - 1];
        const previous = current?.[current.length - 1];
        if (current !== undefined && previous !== undefined && (line.joinAcrossGaps || point.key === previous.key + 1)) {
            current.push(point);
        } else {
            segments.push([point]);
        }
    }

    return segments;
}

/** A line's points in the order their values are worth printing: the latest, the highest, the lowest, then the rest. */
function byLabelPriority(points: PlotSeries['points']): PlotSeries['points'] {
    const last = points[points.length - 1];
    if (last === undefined) {
        return [];
    }
    const highest = points.reduce((best, point) => (point.value > best.value ? point : best), last);
    const lowest = points.reduce((best, point) => (point.value < best.value ? point : best), last);
    const first = [last, highest, lowest].filter((point, index, all) => all.indexOf(point) === index);

    return [...first, ...points.filter((point) => !first.includes(point))];
}

/**
 * Values printed beside the points, as many as fit without overlapping, the
 * most telling first (taking turns between two lines). Of two points on one
 * key, the higher one's value goes above it and the lower one's below.
 */
function placeValueLabels(
    series: PlotSeries[],
    xOf: (key: number) => number,
    yOf: (value: number) => number,
    plotBottom: number,
    formatValue: (value: number) => string,
): Array<{ key: string; x: number; y: number; text: string }> {
    const candidates = series
        .flatMap((line, seriesIndex) => byLabelPriority(line.points).map((point, rank) => ({ line, seriesIndex, point, rank })))
        .sort((first, second) => first.rank - second.rank);
    const labels: Array<{ key: string; x: number; y: number; text: string }> = [];

    for (const { seriesIndex, point } of candidates) {
        const other = series[1 - seriesIndex]?.points.find((candidate) => candidate.key === point.key);
        const pointY = yOf(point.value);
        const higher = other === undefined || point.value > other.value || (point.value === other.value && seriesIndex === 0);
        const below = !higher && pointY + 18 <= plotBottom;
        const x = xOf(point.key);
        const y = below ? pointY + 17 : pointY - 9;
        if (labels.some((label) => Math.abs(label.x - x) < VALUE_LABEL_GAP.across && Math.abs(label.y - y) < VALUE_LABEL_GAP.down)) {
            continue;
        }
        labels.push({ key: `${seriesIndex}-${point.key}`, x, y, text: formatValue(point.value) });
    }

    return labels;
}

function MarkerSwatch({ tone, shape, withLine = false }: { tone: ChartTone; shape: MarkerShape; withLine?: boolean }) {
    const classes = toneClasses(tone);

    return (
        <svg aria-hidden="true" viewBox={withLine ? '0 0 22 12' : '0 0 12 12'} className={cn('h-3 shrink-0', withLine ? 'w-5.5' : 'w-3')}>
            {withLine && <line x1="0" x2="22" y1="6" y2="6" className={classes.stroke} strokeWidth={2.5} />}
            <path d={markerPath(shape, withLine ? 11 : 6, 6, 3.6)} className={cn(classes.fill, 'stroke-white')} strokeWidth={1.2} />
        </svg>
    );
}
