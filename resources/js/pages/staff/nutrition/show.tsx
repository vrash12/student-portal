import { Head, Link, useForm } from '@inertiajs/react';
import { Apple, Pencil, Plus, Trash2, UserRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CandidateMedicalPanel } from '@/components/medical/medical-record-panel';
import {
    DietaryProfileList,
    formatKg,
    Measurements,
    NoteBlock,
    NutritionPlan,
    NutritionStatusLabel,
    NutritionTrendCharts,
} from '@/components/nutrition/nutrition-ui';
import { Button, ButtonLink } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { ProfileMedical } from '@/types/medical';
import type { DietaryProfile, NutritionAssessment, NutritionCandidateSummary, NutritionStandards } from '@/types/nutrition';

interface NutritionRecordProps {
    candidate: NutritionCandidateSummary;
    /** Newest first. */
    assessments: NutritionAssessment[];
    dietaryProfile: DietaryProfile;
    standards: NutritionStandards;
    /** View-only medical record for dietitians; null for others. */
    medical: ProfileMedical | null;
    medicalRecordUrl: string | null;
    profileUrl: string | null;
    can: { manage: boolean };
}

/** One candidate's nutrition record: latest assessment, trends, dietary profile, history. */
export default function NutritionRecord({ candidate, assessments, dietaryProfile, standards, medical, profileUrl, can }: NutritionRecordProps) {
    const latest = assessments[0] ?? null;
    const [deleting, setDeleting] = useState<NutritionAssessment | null>(null);

    return (
        <>
            <Head title={`Nutrition · ${candidate.name}`} />

            <PageHeader
                title={candidate.name}
                description={[`Candidate ${candidate.number}`, candidate.className, candidate.campus, candidate.status].filter(Boolean).join(' · ')}
                breadcrumbs={[{ label: 'Nutrition', href: routes.nutrition.index() }, { label: candidate.name }]}
                actions={
                    <>
                        {profileUrl !== null && (
                            <ButtonLink href={profileUrl} icon={<UserRound className="size-4" aria-hidden="true" />}>
                                Profile
                            </ButtonLink>
                        )}
                        {can.manage && (
                            <ButtonLink href={routes.nutrition.assessments.create(candidate.id)} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
                                New Assessment
                            </ButtonLink>
                        )}
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                <div className="flex items-center gap-4">
                    {candidate.photoUrl !== null ? (
                        <img src={candidate.photoUrl} alt={`Picture of ${candidate.name}`} className="size-20 rounded-lg border border-line-box object-cover" />
                    ) : (
                        <span className="flex size-20 items-center justify-center rounded-lg border border-line-box bg-surface-muted text-ink-subtle" aria-hidden="true">
                            <UserRound className="size-8" />
                        </span>
                    )}
                    <div className="flex flex-col gap-1">
                        <NutritionStatusLabel status={latest?.status ?? null} />
                        <p className="text-sm text-ink-muted">
                            {latest === null ? 'No assessment yet.' : `Last assessed ${formatCalendarDate(latest.assessedOn)}${latest.assessedBy ? ` by ${latest.assessedBy}` : ''}.`}
                        </p>
                    </div>
                </div>

                {latest === null ? (
                    <Panel collapsible={false}>
                        <EmptyState
                            icon={Apple}
                            title="Not assessed yet"
                            description={can.manage ? 'Record the first assessment: height, weight and waist, diet history, findings and a plan.' : 'A dietitian of the campus records the assessments.'}
                            action={
                                can.manage ? (
                                    <ButtonLink href={routes.nutrition.assessments.create(candidate.id)} variant="primary" icon={<Plus className="size-4" aria-hidden="true" />}>
                                        New Assessment
                                    </ButtonLink>
                                ) : undefined
                            }
                        />
                    </Panel>
                ) : (
                    <>
                        <Panel title="Latest Assessment" description={formatCalendarDate(latest.assessedOn)} collapsible={false}>
                            <div className="flex flex-col gap-6">
                                <Measurements assessment={latest} />
                                <div className="border-t border-line pt-5">
                                    <NutritionPlan assessment={latest} />
                                </div>
                                <AssessmentNotes assessment={latest} />
                            </div>
                        </Panel>
                        <NutritionTrendCharts assessments={assessments} standards={standards} />
                    </>
                )}

                <DietaryProfilePanel candidateId={candidate.id} profile={dietaryProfile} canEdit={can.manage} />

                {assessments.length > 0 && (
                    <Panel title="All Assessments" description={`${assessments.length} recorded, newest first.`} bodyClassName="p-0">
                        <Table caption="Nutrition assessments" className="min-w-[44rem]">
                            <TableHead>
                                <Th>Date</Th>
                                <Th align="right">Weight</Th>
                                <Th>BMI</Th>
                                <Th align="right">Waist ÷ Height</Th>
                                <Th>By</Th>
                                {can.manage && (
                                    <Th align="right">
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                )}
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
                                        <Td align="right" numeric className="text-ink">
                                            {assessment.waistToHeight === null ? '—' : assessment.waistToHeight.toFixed(2)}
                                        </Td>
                                        <Td className="text-ink">{assessment.assessedBy ?? '—'}</Td>
                                        {can.manage && (
                                            <Td align="right">
                                                <span className="inline-flex gap-1">
                                                    <Link
                                                        href={routes.nutrition.assessments.edit(assessment.id)}
                                                        aria-label={`Correct the assessment of ${formatCalendarDate(assessment.assessedOn)}`}
                                                        className="inline-flex h-9 items-center gap-1 rounded-md px-3 font-medium text-primary-700 hover:bg-primary-50 pointer-coarse:h-11"
                                                    >
                                                        <Pencil className="size-4" aria-hidden="true" />
                                                        Correct
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleting(assessment)}
                                                        aria-label={`Delete the assessment of ${formatCalendarDate(assessment.assessedOn)}`}
                                                        className="inline-flex h-9 items-center gap-1 rounded-md px-3 font-medium text-danger-fg hover:bg-danger-bg pointer-coarse:h-11"
                                                    >
                                                        <Trash2 className="size-4" aria-hidden="true" />
                                                        Delete
                                                    </button>
                                                </span>
                                            </Td>
                                        )}
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}

                {medical !== null && <CandidateMedicalPanel medical={medical} candidateId={candidate.id} />}
            </div>

            {deleting !== null && <DeleteAssessmentDialog assessment={deleting} onClose={() => setDeleting(null)} />}
        </>
    );
}

/** The dietitian's own notes (staff only). Nothing shows when none were written. */
function AssessmentNotes({ assessment }: { assessment: NutritionAssessment }) {
    const notes: Array<[string, string | null]> = [
        ['Diagnosis', assessment.diagnosis],
        ['Diet History', assessment.dietHistory],
        ['Clinical Findings', assessment.clinicalFindings],
        ['Lab Findings', assessment.labFindings],
    ];
    const written = notes.filter((note): note is [string, string] => note[1] !== null);
    const habits = [assessment.activityLevel ? `Activity: ${assessment.activityLevel.label}` : null, assessment.mealsPerDay !== null ? `${assessment.mealsPerDay} meals a day` : null].filter(Boolean);

    if (written.length === 0 && habits.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4 border-t border-line pt-5">
            <p className="text-xs font-semibold uppercase tracking-wider text-primary-800">Dietitian's Notes (staff only)</p>
            {habits.length > 0 && <p className="text-sm text-ink">{habits.join(' · ')}</p>}
            {written.map(([label, text]) => (
                <NoteBlock key={label} label={label}>
                    {text}
                </NoteBlock>
            ))}
        </div>
    );
}

function DietaryProfilePanel({ candidateId, profile, canEdit }: { candidateId: number; profile: DietaryProfile; canEdit: boolean }) {
    const formatDate = useDateFormatter();
    const [editing, setEditing] = useState(false);
    const form = useForm({
        food_allergies: profile.foodAllergies ?? '',
        dietary_restrictions: profile.dietaryRestrictions ?? '',
        supplements: profile.supplements ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.nutrition.dietaryProfile(candidateId), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <Panel
            title="Dietary Profile"
            description={`Allergies and restrictions are also shown to the instructors of the ${terms.classBatch.singular.toLowerCase()}.${profile.updatedAt ? ` Updated ${formatDate.dateTime(profile.updatedAt)}${profile.updatedBy ? ` by ${profile.updatedBy}` : ''}.` : ''}`}
            collapsible={false}
            actions={
                canEdit && !editing ? (
                    <Button variant="secondary" icon={<Pencil className="size-4" aria-hidden="true" />} onClick={() => setEditing(true)}>
                        Edit
                    </Button>
                ) : undefined
            }
        >
            {editing ? (
                <form onSubmit={submit} noValidate className="flex flex-col gap-4">
                    <FormField label="Food Allergies" error={form.errors.food_allergies} hint="For example: shrimp, peanuts. Leave empty when none.">
                        <TextInput value={form.data.food_allergies} maxLength={500} onChange={(event) => form.setData('food_allergies', event.target.value)} />
                    </FormField>
                    <FormField label="Dietary Restrictions" error={form.errors.dietary_restrictions} hint="For example: no pork, lactose intolerant.">
                        <TextInput value={form.data.dietary_restrictions} maxLength={500} onChange={(event) => form.setData('dietary_restrictions', event.target.value)} />
                    </FormField>
                    <FormField label="Supplements" error={form.errors.supplements} hint="Vitamins, protein or others taken.">
                        <TextInput value={form.data.supplements} maxLength={500} onChange={(event) => form.setData('supplements', event.target.value)} />
                    </FormField>
                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={form.processing}
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Profile
                        </Button>
                    </div>
                </form>
            ) : (
                <DietaryProfileList profile={profile} />
            )}
        </Panel>
    );
}

function DeleteAssessmentDialog({ assessment, onClose }: { assessment: NutritionAssessment; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.delete(routes.nutrition.assessments.destroy(assessment.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open
            title="Delete this assessment?"
            description={`The assessment of ${formatCalendarDate(assessment.assessedOn)} is removed for good. Use Correct for a mistake in it. Recorded in Audit History.`}
            busy={form.processing}
            onClose={onClose}
            footer={
                <>
                    <Button type="button" variant="ghost" disabled={form.processing} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" form="delete-nutrition-assessment" variant="danger" loading={form.processing}>
                        Delete Assessment
                    </Button>
                </>
            }
        >
            <form id="delete-nutrition-assessment" onSubmit={submit} noValidate>
                <FormField label="Reason" required error={form.errors.reason}>
                    <TextArea rows={3} maxLength={500} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} placeholder="Entered for the wrong candidate" />
                </FormField>
            </form>
        </Dialog>
    );
}
