import { Link } from '@inertiajs/react';
import { Apple } from 'lucide-react';
import { PieChart } from '@/components/charts/pie-chart';
import { NutritionStatusLabel } from '@/components/nutrition/nutrition-ui';
import { ButtonLink } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';
import type { NutritionOverview } from '@/types/nutrition';

/** Dashboard: BMI categories of the campus and the candidates the dietitian should see first. */
export function NutritionDashboardPanel({ overview }: { overview: NutritionOverview }) {
    const slices = [
        { label: 'Normal', value: overview.byStatus.normal, tone: 'passing' as const },
        { label: 'Underweight', value: overview.byStatus.underweight, tone: 'atRisk' as const },
        { label: 'Overweight', value: overview.byStatus.overweight, tone: 'c4' as const },
        { label: 'Obese', value: overview.byStatus.obese, tone: 'failing' as const },
        { label: 'Not assessed', value: overview.notAssessed, tone: 'incomplete' as const },
    ];

    return (
        <Panel
            title="Nutrition"
            description={`${overview.needsAttention} of ${overview.total} candidates need attention · ${overview.reviewDue} reviews due`}
            actions={
                <ButtonLink href={routes.nutrition.index({ show: 'attention' })} icon={<Apple className="size-4" aria-hidden="true" />}>
                    Open Nutrition
                </ButtonLink>
            }
        >
            {overview.total === 0 ? (
                <p className="text-sm text-ink-muted">No candidates yet.</p>
            ) : (
                <div className="grid gap-6 lg:grid-cols-2">
                    <PieChart slices={slices} noun={{ one: 'candidate', other: 'candidates' }} />
                    <div>
                        <h3 className="text-sm font-semibold text-ink">See First</h3>
                        {overview.attention.length === 0 ? (
                            <p className="mt-2 text-sm text-ink-muted">Nobody needs attention now.</p>
                        ) : (
                            <ul className="mt-2 divide-y divide-line">
                                {overview.attention.map((row) => (
                                    <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                        <Link href={routes.nutrition.show(row.id)} className="min-w-0 text-sm font-medium text-primary-700 hover:underline">
                                            {row.name}
                                            <span className="block text-xs font-normal text-ink-muted">{[row.number, row.className].filter(Boolean).join(' · ')}</span>
                                        </Link>
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            {row.assessedOn !== null && <NutritionStatusLabel status={row.status} />}
                                            {row.assessedOn === null && <StatusBadge tone="neutral">Not assessed</StatusBadge>}
                                            {row.reviewDue && <StatusBadge tone="warning">Review due</StatusBadge>}
                                            {row.waistAtRisk && <StatusBadge tone="warning">Waist at risk</StatusBadge>}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </Panel>
    );
}
