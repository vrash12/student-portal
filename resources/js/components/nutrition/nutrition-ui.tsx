import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { ChartFigure } from '@/components/charts/chart-figure';
import { LineChart } from '@/components/charts/line-chart';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/cn';
import { formatCalendarDate } from '@/lib/format';
import type { DietaryProfile, NutritionAssessment, NutritionStandards, NutritionStatusBadge } from '@/types/nutrition';

/** The BMI category as a badge with its name (never color alone). */
export function NutritionStatusLabel({ status }: { status: NutritionStatusBadge | null }) {
    if (status === null) {
        return <StatusBadge tone="neutral">Not assessed</StatusBadge>;
    }

    return <StatusBadge tone={status.tone}>{status.label}</StatusBadge>;
}

export function formatKg(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)} kg`;
}

/** "+1.5 kg", "−0.8 kg", "No change". */
export function formatWeightChange(change: number | null): string {
    if (change === null) {
        return '—';
    }
    if (change === 0) {
        return 'No change';
    }

    return `${change > 0 ? '+' : '−'}${Math.abs(change).toFixed(1)} kg`;
}

/** Height, weight, BMI with its category, waist and its ratio to height, body fat. */
export function Measurements({ assessment }: { assessment: NutritionAssessment }) {
    return (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
            <Measure label="Height">{assessment.heightCm.toFixed(1)} cm</Measure>
            <Measure label="Weight">{formatKg(assessment.weightKg)}</Measure>
            <Measure label="Body Mass Index">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="tabular-nums">{assessment.bmi.toFixed(1)}</span>
                    <NutritionStatusLabel status={assessment.status} />
                </span>
            </Measure>
            <Measure label="Waist">{assessment.waistCm === null ? '—' : `${assessment.waistCm.toFixed(1)} cm`}</Measure>
            <Measure label="Waist-to-Height Ratio">
                {assessment.waistToHeight === null ? (
                    '—'
                ) : (
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular-nums">{assessment.waistToHeight.toFixed(2)}</span>
                        {assessment.waistAtRisk && <StatusBadge tone="warning">At risk</StatusBadge>}
                    </span>
                )}
            </Measure>
            <Measure label="Body Fat">{assessment.bodyFatPercent === null ? '—' : `${assessment.bodyFatPercent.toFixed(1)}%`}</Measure>
        </dl>
    );
}

/** The dietitian's plan: goal, targets, what to do, and the next review. */
export function NutritionPlan({ assessment }: { assessment: NutritionAssessment }) {
    const hasTargets = assessment.goal !== null || assessment.targetWeightKg !== null || assessment.energyTargetKcal !== null;

    return (
        <div className="flex flex-col gap-4">
            {hasTargets && (
                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                    <Measure label="Goal">{assessment.goal?.label ?? '—'}</Measure>
                    <Measure label="Target Weight">{formatKg(assessment.targetWeightKg)}</Measure>
                    <Measure label="Daily Energy">{assessment.energyTargetKcal === null ? '—' : `${assessment.energyTargetKcal.toLocaleString('en-US')} kcal`}</Measure>
                </dl>
            )}
            {assessment.plan !== null && <NoteBlock label="Plan">{assessment.plan}</NoteBlock>}
            {!hasTargets && assessment.plan === null && <p className="text-sm text-ink-muted">No plan was written for this assessment.</p>}
            <p className="text-sm text-ink">
                Next review: <strong className="font-semibold">{assessment.nextReviewOn === null ? 'Not set' : formatCalendarDate(assessment.nextReviewOn)}</strong>
            </p>
        </div>
    );
}

/** Weight and BMI over time, with the BMI cut-offs as reference lines. Needs two assessments. */
export function NutritionTrendCharts({ assessments, standards }: { assessments: NutritionAssessment[]; standards: NutritionStandards }) {
    if (assessments.length < 2) {
        return null;
    }
    const oldestFirst = [...assessments].reverse();

    return (
        <div className="grid gap-5 lg:grid-cols-2">
            <ChartFigure title="Weight" description="Kilograms at each assessment.">
                <LineChart
                    data={{ xType: 'date', series: [{ label: 'Weight (kg)', tone: 'c1', points: oldestFirst.map((item) => ({ date: item.assessedOn, value: item.weightKg })) }] }}
                    format="count"
                    label="Weight over time"
                    xLabel="Date"
                    yMax={Math.ceil(Math.max(...oldestFirst.map((item) => item.weightKg)) / 10) * 10 + 10}
                />
            </ChartFigure>
            <ChartFigure title="Body Mass Index" description={`Lines mark the normal range (${standards.underweightBelow} to under ${standards.overweightFrom}). Obese from ${standards.obeseFrom}.`}>
                <LineChart
                    data={{ xType: 'date', series: [{ label: 'BMI', tone: 'c2', points: oldestFirst.map((item) => ({ date: item.assessedOn, value: item.bmi, detail: item.status.label })) }] }}
                    format="count"
                    yMax={40}
                    references={[
                        { label: 'Underweight below', value: standards.underweightBelow },
                        { label: 'Overweight from', value: standards.overweightFrom },
                    ]}
                    label="Body mass index over time"
                    xLabel="Date"
                />
            </ChartFigure>
        </div>
    );
}

/** What the candidate must not eat and what they take. Supplements are left out for instructors. */
export function DietaryProfileList({ profile, showSupplements = true }: { profile: Pick<DietaryProfile, 'foodAllergies' | 'dietaryRestrictions' | 'supplements'>; showSupplements?: boolean }) {
    return (
        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-3">
            <Measure label="Food Allergies">
                {profile.foodAllergies === null ? (
                    'None recorded'
                ) : (
                    <span className="flex items-start gap-1.5 font-medium text-danger-fg">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {profile.foodAllergies}
                    </span>
                )}
            </Measure>
            <Measure label="Dietary Restrictions">{profile.dietaryRestrictions ?? 'None recorded'}</Measure>
            {showSupplements && <Measure label="Supplements">{profile.supplements ?? 'None recorded'}</Measure>}
        </dl>
    );
}

export function NoteBlock({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <p className="text-sm font-medium text-ink-muted">{label}</p>
            <p className="mt-1 whitespace-pre-line text-sm text-ink">{children}</p>
        </div>
    );
}

function Measure({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('min-w-0', className)}>
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-1 text-sm text-ink">{children}</dd>
        </div>
    );
}
