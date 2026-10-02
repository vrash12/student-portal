import type { ChartTone } from '@/types/charts';

/**
 * Tailwind classes of each chart color (tokens --color-chart-* in app.css).
 * Written out in full so Tailwind generates them; never build them from parts.
 */
const TONES: Record<ChartTone, { bg: string; fill: string; fillSoft: string; stroke: string }> = {
    passing: { bg: 'bg-chart-passing', fill: 'fill-chart-passing', fillSoft: 'fill-chart-passing/15', stroke: 'stroke-chart-passing' },
    atRisk: { bg: 'bg-chart-at-risk', fill: 'fill-chart-at-risk', fillSoft: 'fill-chart-at-risk/15', stroke: 'stroke-chart-at-risk' },
    failing: { bg: 'bg-chart-failing', fill: 'fill-chart-failing', fillSoft: 'fill-chart-failing/15', stroke: 'stroke-chart-failing' },
    incomplete: { bg: 'bg-chart-incomplete', fill: 'fill-chart-incomplete', fillSoft: 'fill-chart-incomplete/15', stroke: 'stroke-chart-incomplete' },
    none: { bg: 'bg-chart-none', fill: 'fill-chart-none', fillSoft: 'fill-chart-none/30', stroke: 'stroke-chart-none' },
    c1: { bg: 'bg-chart-1', fill: 'fill-chart-1', fillSoft: 'fill-chart-1/15', stroke: 'stroke-chart-1' },
    c2: { bg: 'bg-chart-2', fill: 'fill-chart-2', fillSoft: 'fill-chart-2/15', stroke: 'stroke-chart-2' },
    c3: { bg: 'bg-chart-3', fill: 'fill-chart-3', fillSoft: 'fill-chart-3/15', stroke: 'stroke-chart-3' },
    c4: { bg: 'bg-chart-4', fill: 'fill-chart-4', fillSoft: 'fill-chart-4/15', stroke: 'stroke-chart-4' },
    c5: { bg: 'bg-chart-5', fill: 'fill-chart-5', fillSoft: 'fill-chart-5/15', stroke: 'stroke-chart-5' },
    c6: { bg: 'bg-chart-6', fill: 'fill-chart-6', fillSoft: 'fill-chart-6/15', stroke: 'stroke-chart-6' },
};

/** Category colors in order; a seventh category starts again with the first. */
const CATEGORY_TONES: readonly ChartTone[] = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6'];

export function toneClasses(tone: ChartTone): { bg: string; fill: string; fillSoft: string; stroke: string } {
    return TONES[tone];
}

/** The given tone, or the category color of the index when the server sent none. */
export function toneAt(index: number, tone?: ChartTone | null): ChartTone {
    return tone ?? CATEGORY_TONES[index % CATEGORY_TONES.length] ?? 'c1';
}

/**
 * Point markers of line series, in order, so lines are told apart by shape
 * as well as color (never color alone, UI_UX_DESIGN.md §47).
 */
export type MarkerShape = 'circle' | 'square' | 'triangle' | 'diamond';

const SHAPES: readonly MarkerShape[] = ['circle', 'square', 'triangle', 'diamond'];

export function markerAt(index: number): MarkerShape {
    return SHAPES[index % SHAPES.length] ?? 'circle';
}

/** SVG path of a marker centred on (x, y); `size` is about its half width. */
export function markerPath(shape: MarkerShape, x: number, y: number, size: number): string {
    switch (shape) {
        case 'square':
            return `M${x - size} ${y - size}h${size * 2}v${size * 2}h${-size * 2}Z`;
        case 'triangle':
            return `M${x} ${y - size * 1.25}L${x + size * 1.2} ${y + size * 0.85}L${x - size * 1.2} ${y + size * 0.85}Z`;
        case 'diamond':
            return `M${x} ${y - size * 1.35}L${x + size * 1.35} ${y}L${x} ${y + size * 1.35}L${x - size * 1.35} ${y}Z`;
        default:
            return `M${x - size} ${y}a${size} ${size} 0 1 0 ${size * 2} 0a${size} ${size} 0 1 0 ${-size * 2} 0`;
    }
}
