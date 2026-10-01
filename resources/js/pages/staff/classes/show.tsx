import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, GraduationCap, Pencil, Plus, SlidersHorizontal, Trash2, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { WeightSummary } from '@/components/grading/offering-context';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { Paginated } from '@/types';

interface AssignedInstructor {
    assignmentId: number;
    id: number;
    name: string;
    isActive: boolean;
}

interface Offering {
    id: number;
    subject: { code: string; name: string; isActive: boolean };
    instructors: AssignedInstructor[];
    /** Grading categories and weights; empty when grading is not set up. */
    grading: Array<{ name: string; weight: string }>;
    assessmentCount: number;
}

interface CandidateRow {
    id: number;
    candidateNumber: string;
    name: string;
    status: { label: string; tone: StatusTone };
}

interface InstructorOption {
    id: number;
    name: string;
    username: string;
}

interface ClassShowProps {
    classBatch: { id: number; name: string; period: { id: number; name: string; isActive: boolean } };
    offerings: Offering[];
    candidates: Paginated<CandidateRow>;
    subjectOptions: Array<{ id: number; code: string; name: string }>;
    instructorOptions: InstructorOption[];
    can: { manageAssignments: boolean; viewCandidates: boolean; configureGrading: boolean; viewPeriod: boolean };
}

export default function ClassShow({ classBatch, offerings, candidates, subjectOptions, instructorOptions, can }: ClassShowProps) {
    const { singular, plural } = terms.classBatch;
    const offeringPagination = useClientPagination(offerings);

    return (
        <>
            <Head title={classBatch.name} />

            <PageHeader
                title={classBatch.name}
                description={
                    <>
                        Academic period:{' '}
                        {can.viewPeriod ? (
                            <Link href={routes.academicPeriods.show(classBatch.period.id)} className="text-primary-700 underline">
                                {classBatch.period.name}
                            </Link>
                        ) : (
                            classBatch.period.name
                        )}{' '}
                        {classBatch.period.isActive && <StatusBadge tone="success">Active</StatusBadge>}
                    </>
                }
                breadcrumbs={[{ label: plural, href: routes.classes.index() }, { label: classBatch.name }]}
                actions={
                    <ButtonLink href={routes.classes.edit(classBatch.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                        Edit {singular}
                    </ButtonLink>
                }
            />

            <div className="flex flex-col gap-6">
                <Panel title="Subjects & Instructors" description={`Subjects this ${singular.toLowerCase()} takes and who teaches each one.`}>
                    <div className="flex flex-col gap-5">
                        {subjectOptions.length > 0 && <AddSubjectForm classId={classBatch.id} options={subjectOptions} />}

                        {offerings.length === 0 ? (
                            <EmptyState
                                icon={BookOpen}
                                headingLevel="h3"
                                title="No subjects yet"
                                description={`Add the subjects this ${singular.toLowerCase()} takes, then assign an instructor to each.`}
                            />
                        ) : (
                            <ul className={cn('divide-y divide-line', subjectOptions.length > 0 ? 'border-t border-line' : '[&>li:first-child]:pt-0')}>
                                {offeringPagination.rows.map((offering) => (
                                    <OfferingItem
                                        key={offering.id}
                                        classBatch={classBatch}
                                        offering={offering}
                                        instructorOptions={instructorOptions}
                                        canManageAssignments={can.manageAssignments}
                                        canConfigureGrading={can.configureGrading}
                                    />
                                ))}
                            </ul>
                        )}
                        <ClientPagination pagination={offeringPagination} noun={{ one: 'subject', other: 'subjects' }} label="Subject pages" />
                    </div>
                </Panel>

                <Panel
                    title="Candidates"
                    description={`${candidates.total} ${candidates.total === 1 ? 'candidate' : 'candidates'} in this ${singular.toLowerCase()}.`}
                    bodyClassName="p-0"
                >
                    {candidates.data.length === 0 ? (
                        <EmptyState
                            icon={GraduationCap}
                            headingLevel="h3"
                            title={`No candidates in this ${singular.toLowerCase()}`}
                            description={`Assign candidates to this ${singular.toLowerCase()} from their candidate records.`}
                        />
                    ) : (
                        <Table caption={`Candidates in ${classBatch.name}`} className="min-w-[32rem]">
                            <TableHead>
                                <Th>Candidate No.</Th>
                                <Th>Name</Th>
                                <Th>Status</Th>
                                <Th align="right">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </TableHead>
                            <TableBody>
                                {candidates.data.map((candidate) => (
                                    <Tr key={candidate.id}>
                                        <Td className="font-medium text-ink" numeric>
                                            {candidate.candidateNumber}
                                        </Td>
                                        <Td className="text-ink">{candidate.name}</Td>
                                        <Td>
                                            <StatusBadge tone={candidate.status.tone}>{candidate.status.label}</StatusBadge>
                                        </Td>
                                        <Td align="right">
                                            {can.viewCandidates && (
                                                <RowAction href={routes.candidates.show(candidate.id)} label={`View ${candidate.name}`}>
                                                    View
                                                </RowAction>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={candidates} noun={{ one: 'candidate', other: 'candidates' }} />
                </Panel>
            </div>
        </>
    );
}

function AddSubjectForm({ classId, options }: { classId: number; options: ClassShowProps['subjectOptions'] }) {
    const form = useForm({ subject_id: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.classes.addSubject(classId), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <FormField label="Add Subject" error={form.errors.subject_id} className="sm:w-80">
                <SelectInput value={form.data.subject_id} onChange={(event) => form.setData('subject_id', event.target.value)}>
                    <option value="">Select a subject</option>
                    {options.map((subject) => (
                        <option key={subject.id} value={String(subject.id)}>
                            {subject.name} ({subject.code})
                        </option>
                    ))}
                </SelectInput>
            </FormField>
            <Button
                type="submit"
                variant="secondary"
                loading={form.processing}
                disabled={form.data.subject_id === ''}
                icon={<Plus className="size-4" aria-hidden="true" />}
            >
                Add Subject
            </Button>
        </form>
    );
}

interface OfferingItemProps {
    classBatch: ClassShowProps['classBatch'];
    offering: Offering;
    instructorOptions: InstructorOption[];
    canManageAssignments: boolean;
    canConfigureGrading: boolean;
}

function OfferingItem({ classBatch, offering, instructorOptions, canManageAssignments, canConfigureGrading }: OfferingItemProps) {
    const { subject, instructors } = offering;
    const hasAssessments = offering.assessmentCount > 0;
    const assignedIds = new Set(instructors.map((instructor) => instructor.id));
    const availableInstructors = instructorOptions.filter((option) => !assignedIds.has(option.id));

    return (
        <li className="flex flex-col gap-3 py-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium text-ink">{subject.name}</p>
                    <p className="text-sm text-ink-muted">
                        {subject.code}
                        {!subject.isActive && ' · Subject is inactive'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    {canConfigureGrading && (
                        <ButtonLink
                            href={routes.classes.grading(classBatch.id, offering.id)}
                            variant="ghost"
                            size="sm"
                            icon={<SlidersHorizontal className="size-4" aria-hidden="true" />}
                            aria-label={`Grading setup for ${subject.name}`}
                        >
                            Grading Setup
                        </ButtonLink>
                    )}
                    <ConfirmAction
                        href={routes.classes.removeSubject(classBatch.id, offering.id)}
                        method="delete"
                        ariaLabel={`Remove ${subject.name} from ${classBatch.name}`}
                        icon={<Trash2 className="size-4" aria-hidden="true" />}
                        disabled={hasAssessments}
                        title="Remove subject?"
                        description={
                            <>
                                <p>
                                    {subject.name} will be removed from {classBatch.name}.
                                </p>
                                {instructors.length > 0 && (
                                    <p>Its instructor assignments ({instructors.map((instructor) => instructor.name).join(', ')}) will also be removed.</p>
                                )}
                                {offering.grading.length > 0 && <p>Its grading setup will also be removed.</p>}
                            </>
                        }
                        confirmLabel="Remove Subject"
                    >
                        Remove
                    </ConfirmAction>
                </div>
            </div>

            <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Grading</p>
                {offering.grading.length === 0 ? (
                    <p className="text-sm text-ink-muted">Not set up yet. Instructors can create assessments once the categories and weights are set.</p>
                ) : (
                    <WeightSummary categories={offering.grading} />
                )}
                {hasAssessments && (
                    <p className="mt-1.5 text-sm text-ink-muted">
                        {offering.assessmentCount} {offering.assessmentCount === 1 ? 'assessment' : 'assessments'} recorded. This subject can no longer be
                        removed from the {terms.classBatch.singular.toLowerCase()}.
                    </p>
                )}
            </div>

            <div>
                <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-ink-subtle">Instructors</p>
                {instructors.length === 0 ? (
                    <p className="text-sm text-ink-muted">No instructor assigned yet.</p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {instructors.map((instructor) => (
                            <li
                                key={instructor.assignmentId}
                                className="inline-flex items-center gap-1 rounded-md border border-line bg-surface-muted py-0.5 pl-3 pr-0.5 text-sm text-ink"
                            >
                                {instructor.name}
                                {!instructor.isActive && <span className="text-xs text-ink-muted">(deactivated)</span>}
                                {canManageAssignments && (
                                    <ConfirmAction
                                        href={routes.instructorAssignments.destroy(instructor.assignmentId)}
                                        method="delete"
                                        ariaLabel={`Remove ${instructor.name} from ${subject.name}`}
                                        icon={<X className="size-4" aria-hidden="true" />}
                                        title="Remove instructor assignment?"
                                        description={
                                            <p>
                                                {instructor.name} will no longer teach {subject.name} for {classBatch.name}.
                                            </p>
                                        }
                                        confirmLabel="Remove Assignment"
                                    >
                                        {null}
                                    </ConfirmAction>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {canManageAssignments && availableInstructors.length > 0 && (
                    <AssignInstructorForm offeringId={offering.id} subjectName={subject.name} options={availableInstructors} />
                )}
            </div>
        </li>
    );
}

function AssignInstructorForm({ offeringId, subjectName, options }: { offeringId: number; subjectName: string; options: InstructorOption[] }) {
    const form = useForm({ class_subject_id: String(offeringId), instructor_id: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.instructorAssignments.store(), {
            preserveScroll: true,
            onSuccess: () => form.reset('instructor_id'),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
            <FormField
                label={
                    <>
                        Assign Instructor<span className="sr-only"> to {subjectName}</span>
                    </>
                }
                error={form.errors.instructor_id}
                className="sm:w-72"
            >
                <SelectInput value={form.data.instructor_id} onChange={(event) => form.setData('instructor_id', event.target.value)}>
                    <option value="">Select an instructor</option>
                    {options.map((instructor) => (
                        <option key={instructor.id} value={String(instructor.id)}>
                            {instructor.name}
                        </option>
                    ))}
                </SelectInput>
            </FormField>
            <Button type="submit" variant="secondary" loading={form.processing} disabled={form.data.instructor_id === ''}>
                Assign
            </Button>
        </form>
    );
}
