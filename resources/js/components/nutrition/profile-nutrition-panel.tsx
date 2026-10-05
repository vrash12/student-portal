import { Apple } from 'lucide-react';
import { DietaryProfileList, formatKg, NutritionStatusLabel } from '@/components/nutrition/nutrition-ui';
import { ButtonLink } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { formatCalendarDate } from '@/lib/format';
import type { ProfileNutrition } from '@/types/nutrition';

/**
 * Nutrition on the staff candidate profile: the latest assessment with a
 * link for nutrition staff; only the BMI category and what the candidate
 * must not eat for instructors of the class.
 */
export function ProfileNutritionPanel({ nutrition }: { nutrition: ProfileNutrition }) {
    const full = nutrition.scope === 'full';

    return (
        <Panel
            title="Nutrition"
            description={full ? 'Confidential. Latest assessment by the dietitian.' : 'BMI category and what the candidate must not eat. The full record is kept by the dietitian.'}
            actions={
                nutrition.recordUrl !== null ? (
                    <ButtonLink href={nutrition.recordUrl} icon={<Apple className="size-4" aria-hidden="true" />}>
                        Nutrition Record
                    </ButtonLink>
                ) : undefined
            }
        >
            <div className="flex flex-col gap-5">
                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
                    <div>
                        <dt className="text-sm font-medium text-ink-muted">BMI Category</dt>
                        <dd className="mt-1">
                            <NutritionStatusLabel status={nutrition.status} />
                        </dd>
                    </div>
                    <div>
                        <dt className="text-sm font-medium text-ink-muted">Last Assessed</dt>
                        <dd className="mt-1 text-sm text-ink">{nutrition.assessedOn === null ? 'Never' : formatCalendarDate(nutrition.assessedOn)}</dd>
                    </div>
                    {full && (
                        <>
                            <div>
                                <dt className="text-sm font-medium text-ink-muted">Weight · BMI</dt>
                                <dd className="mt-1 text-sm text-ink tabular-nums">
                                    {formatKg(nutrition.weightKg)}
                                    {nutrition.bmi !== null && ` · ${nutrition.bmi.toFixed(1)}`}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-ink-muted">Next Review</dt>
                                <dd className="mt-1 text-sm text-ink">{nutrition.nextReviewOn === null ? '—' : formatCalendarDate(nutrition.nextReviewOn)}</dd>
                            </div>
                        </>
                    )}
                </dl>
                <DietaryProfileList profile={{ foodAllergies: nutrition.foodAllergies, dietaryRestrictions: nutrition.dietaryRestrictions, supplements: null }} showSupplements={false} />
            </div>
        </Panel>
    );
}
