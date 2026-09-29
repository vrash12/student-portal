import { Head, Link, useForm } from '@inertiajs/react';
import { ClipboardList, Pencil, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface Assignment {
    id: number;
    classBatch: { id: number; name: string; period: string; periodIsActive: boolean };
    subject: { code: string; name: string };
}

interface InstructorShowProps {
    instructor: { id: number; name: string; username: string; isActive: boolean };
    assignments: Assignment[];
    offeringOptions: Array<{ id: number; label: string }>;
    canEditAccount: boolean;
}

export default function InstructorShow({ instructor, assignments, offeringOptions, canEditAccount }: InstructorShowProps) {
    const { singular } = terms.classBatch;

    return (
        <>
            <Head title={instructor.name} />

            <PageHeader
                title={instructor.name}
                description={
                    <>
                        {instructor.username}{' '}
                        {instructor.isActive ? (
                            <StatusBadge tone="success">Active</StatusBadge>
                        ) : (
                            <StatusBadge tone="neutral">Deactivated</StatusBadge>
                        )}
                    </>
                }
                breadcrumbs={[{ label: 'Instructors', href: routes.instructors.index() }, { label: instructor.name }]}
                actions={
                    canEditAccount && (
                        <ButtonLink href={routes.users.edit(instructor.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Account
                        </ButtonLink>
                    )
                }
            />

            <Panel title="Teaching Assignments" description={`Subjects this instructor teaches, by ${singular.toLowerCase()}.`} bodyClassName="p-0">
                <div className="border-b border-line p-5">
                    {!instructor.isActive ? (
                        <Alert tone="warning">This account is deactivated and cannot receive new assignments.</Alert>
                    ) : offeringOptions.length > 0 ? (
                        <AddAssignmentForm instructorId={instructor.id} options={offeringOptions} />
                    ) : (
                        <p className="text-sm text-ink-muted">
                            Every subject in the active academic period already has this instructor, or no subjects have been added to classes yet.
                        </p>
                    )}
                </div>

                {assignments.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        headingLevel="h3"
                        title="No assignments yet"
                        description={`Assign this instructor to a subject above, or from a ${singular.toLowerCase()} page.`}
                    />
                ) : (
                    <Table caption={`Teaching assignments of ${instructor.name}`}>
                        <TableHead>
                            <Th>{singular}</Th>
                            <Th>Subject</Th>
                            <Th>Academic Period</Th>
                            <Th align="right">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </TableHead>
                        <TableBody>
                            {assignments.map((assignment) => (
                                <Tr key={assignment.id}>
                                    <Td>
                                        <Link
                                            href={routes.classes.show(assignment.classBatch.id)}
                                            className="font-medium text-primary-700 hover:underline"
                                        >
                                            {assignment.classBatch.name}
                                        </Link>
                                    </Td>
                                    <Td className="text-ink">
                                        {assignment.subject.name} <span className="text-ink-muted">({assignment.subject.code})</span>
                                    </Td>
                                    <Td className="text-ink-muted">
                                        {assignment.classBatch.period}
                                        {assignment.classBatch.periodIsActive && ' (active)'}
                                    </Td>
                                    <Td align="right">
                                        <ConfirmAction
                                            href={routes.instructorAssignments.destroy(assignment.id)}
                                            method="delete"
                                            ariaLabel={`Remove ${assignment.subject.name} for ${assignment.classBatch.name}`}
                                            icon={<Trash2 className="size-4" aria-hidden="true" />}
                                            title="Remove teaching assignment?"
                                            description={
                                                <p>
                                                    {instructor.name} will no longer teach {assignment.subject.name} for{' '}
                                                    {assignment.classBatch.name}.
                                                </p>
                                            }
                                            confirmLabel="Remove Assignment"
                                        >
                                            Remove
                                        </ConfirmAction>
                                    </Td>
                                </Tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}

function AddAssignmentForm({ instructorId, options }: { instructorId: number; options: InstructorShowProps['offeringOptions'] }) {
    const form = useForm({ class_subject_id: '', instructor_id: String(instructorId) });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.instructorAssignments.store(), {
            preserveScroll: true,
            onSuccess: () => form.reset('class_subject_id'),
        });
    };

    return (
        <div>
            <form onSubmit={submit} noValidate className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <FormField label="Add Assignment" error={form.errors.class_subject_id ?? form.errors.instructor_id} className="sm:w-96">
                    <SelectInput
                        value={form.data.class_subject_id}
                        onChange={(event) => form.setData('class_subject_id', event.target.value)}
                    >
                        <option value="">Select a class and subject</option>
                        {options.map((option) => (
                            <option key={option.id} value={String(option.id)}>
                                {option.label}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
                <Button type="submit" variant="secondary" loading={form.processing} disabled={form.data.class_subject_id === ''}>
                    Assign
                </Button>
            </form>
            <p className="mt-1.5 text-sm text-ink-subtle">Lists subjects of classes in the active academic period.</p>
        </div>
    );
}
