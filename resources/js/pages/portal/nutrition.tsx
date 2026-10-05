import { Head } from '@inertiajs/react';
import { Apple, ClipboardList, History, Ruler, Utensils } from 'lucide-react';
import { DietaryProfileList, formatKg, Measurements, NutritionPlan, NutritionStatusLabel, NutritionTrendCharts } from '@/components/nutrition/nutrition-ui';
import { PortalEmpty, PortalHeading, PortalSection } from '@/components/portal/portal-ui';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate } from '@/lib/format';
import type { DietaryProfile, NutritionAssessment, NutritionStandards } from '@/types/nutrition';

interface PortalNutritionProps {
    candidate: { name: string; number: string; className: string | null };
    /** Own assessments, newest first, without the dietitian's notes. */
    assessments: NutritionAssessment[];
    dietaryProfile: DietaryProfile;
    standards: NutritionStandards;
}

/** Candidate "Nutrition": own measurements, BMI category, the dietitian's plan and the dietary profile. */
export default function PortalNutrition({ assessments, dietaryProfile, standards }: PortalNutritionProps) {
    const [latest] = assessments;

    return (
        <>
            <Head title="Nutrition" />
            <PortalHeading icon={Apple} title="Nutrition" description="Your measurements and the plan from the dietitian. Only you and the staff who look after your health see this." />

            {latest === undefined ? (
                <PortalEmpty icon={Apple} title="No nutrition assessment yet">
                    Your measurements and plan appear here after the dietitian assesses you.
                </PortalEmpty>
            ) : (
                <div className="flex flex-col gap-10">
                    <PortalSection icon={Ruler} title="My Measurements" description={`Latest assessment · ${formatCalendarDate(latest.assessedOn)}`}>
                        <div className="flex flex-col gap-4">
                            <Measurements assessment={latest} />
                            <p className="text-sm text-ink-muted">
                                Body mass index: underweight below {standards.underweightBelow}, normal from {standards.underweightBelow} to under {standards.overweightFrom}, overweight from {standards.overweightFrom}, obese from {standards.obeseFrom}.
                            </p>
                        </div>
                    </PortalSection>

                    <PortalSection icon={ClipboardList} title="My Plan">
                        <NutritionPlan assessment={latest} />
                    </PortalSection>

                    <NutritionTrendCharts assessments={assessments} standards={standards} />

                    <PortalSection icon={Utensils} title="My Dietary Profile" description="Recorded by the dietitian. Tell them if something is missing or wrong.">
                        <DietaryProfileList profile={dietaryProfile} />
                    </PortalSection>

                    {assessments.length > 1 && (
                        <PortalSection icon={History} title="Earlier Assessments" flush>
                            <Table caption="My nutrition assessments">
                                <TableHead>
                                    <Th>Date</Th>
                                    <Th align="right">Weight</Th>
                                    <Th>BMI</Th>
                                </TableHead>
                                <TableBody>
                                    {assessments.map((assessment) => (
                                        <Tr key={assessment.id}>
                                            <Td className="whitespace-nowrap text-ink">{formatCalendarDate(assessment.assessedOn)}</Td>
                                            <Td align="right" numeric className="text-ink">
                                                {formatKg(assessment.weightKg)}
                                            </Td>
                                            <Td className="whitespace-nowrap text-ink">
                                                <span className="flex items-center gap-2">
                                                    <span className="tabular-nums">{assessment.bmi.toFixed(1)}</span>
                                                    <NutritionStatusLabel status={assessment.status} />
                                                </span>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                        </PortalSection>
                    )}
                </div>
            )}
        </>
    );
}
