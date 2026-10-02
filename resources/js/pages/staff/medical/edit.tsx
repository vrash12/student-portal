import { Head, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { FormEvent } from 'react';
import { Audience, formatMedicalValue } from '@/components/medical/medical-record-panel';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MedicalFieldDefinition, MedicalFieldTypeValue } from '@/types/medical';

/** Same limits as MedicalFieldType. */
const TEXT_MAX = 255;
const LONG_TEXT_MAX = 2000;

interface MedicalHistoryEntry {
    id: number;
    field: string;
    type: MedicalFieldTypeValue;
    previousValue: string | null;
    newValue: string | null;
    changedBy: string;
    changedAt: string | null;
}

interface EditMedicalRecordProps {
    candidate: { id: number; number: string; name: string; className: string | null };
    fields: Array<MedicalFieldDefinition & { value: string | null }>;
    history: MedicalHistoryEntry[];
}

/** One candidate's medical record: every active field, and the change history. */
export default function EditMedicalRecord({ candidate, fields, history }: EditMedicalRecordProps) {
    const formatDate = useDateFormatter();
    const form = useForm<{ values: Record<string, string> }>({
        values: Object.fromEntries(fields.map((field) => [String(field.id), field.value ?? ''])),
    });
    const historyPages = useClientPagination(history);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.medical.records.update(candidate.id), { preserveScroll: true });
    };
    const setValue = (fieldId: number, value: string) => form.setData('values', { ...form.data.values, [String(fieldId)]: value });
    const errorFor = (fieldId: number): string | undefined => (form.errors as Record<string, string | undefined>)[`values.${fieldId}`];

    return (
        <>
            <Head title={`Medical Record · ${candidate.name}`} />

            <PageHeader
                title={`Medical Record of ${candidate.name}`}
                description={`Candidate ${candidate.number}${candidate.className ? ` · ${candidate.className}` : ''}. Confidential.`}
                breadcrumbs={[
                    { label: 'Medical Records', href: routes.medical.records.index() },
                    { label: candidate.name, href: routes.candidates.show(candidate.id) },
                    { label: 'Medical Record' },
                ]}
            />

            <div className="flex flex-col gap-6">
                <form onSubmit={submit} noValidate>
                    <Panel title="Record" description="Leave a field empty when it is not known. Marks show who else sees a field.">
                        {fields.length === 0 ? (
                            <p className="text-sm text-ink-muted">The medical record has no active fields. An administrator adds them under Configure Fields.</p>
                        ) : (
                            <div className="grid gap-5 sm:grid-cols-2">
                                {fields.map((field) => (
                                    <div key={field.id} className={field.type.value === 'long_text' ? 'sm:col-span-2' : undefined}>
                                        <FormField label={field.name} error={errorFor(field.id)} hint={field.helpText ?? undefined}>
                                            <ValueInput field={field} value={form.data.values[String(field.id)] ?? ''} onChange={(value) => setValue(field.id, value)} />
                                        </FormField>
                                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                                            <Audience instructors={field.visibleToInstructors} candidate={field.visibleToCandidate} />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>

                    {fields.length > 0 && (
                        <FormActions>
                            <ButtonLink href={routes.candidates.show(candidate.id)} variant="secondary">
                                Back to Profile
                            </ButtonLink>
                            <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                                Save Medical Record
                            </Button>
                        </FormActions>
                    )}
                </form>

                <Panel title="Change History" description="Every change to this record, newest first. Visible to medical staff only." bodyClassName="p-0">
                    {history.length === 0 ? (
                        <p className="px-5 py-6 text-sm text-ink-muted">No changes recorded yet.</p>
                    ) : (
                        <>
                            <Table caption="Medical record change history" className="min-w-[44rem]">
                                <TableHead>
                                    <Th>When</Th>
                                    <Th>Field</Th>
                                    <Th>Change</Th>
                                    <Th>By</Th>
                                </TableHead>
                                <TableBody>
                                    {historyPages.rows.map((entry) => (
                                        <Tr key={entry.id}>
                                            <Td className="whitespace-nowrap text-ink">{formatDate.dateTime(entry.changedAt)}</Td>
                                            <Td className="text-ink">{entry.field}</Td>
                                            <Td className="text-ink">
                                                <span className="inline-flex flex-wrap items-center gap-1.5">
                                                    <span className="text-ink-muted">{formatMedicalValue(entry.type, entry.previousValue) ?? 'Empty'}</span>
                                                    <ArrowRight className="size-3.5 text-ink-muted" aria-label="changed to" />
                                                    <span className="font-medium">{formatMedicalValue(entry.type, entry.newValue) ?? 'Empty'}</span>
                                                </span>
                                            </Td>
                                            <Td className="text-ink">{entry.changedBy}</Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                            <ClientPagination pagination={historyPages} noun={{ one: 'change', other: 'changes' }} label="Change history pages" />
                        </>
                    )}
                </Panel>
            </div>
        </>
    );
}

/** The input that fits the field's kind of answer. */
function ValueInput({ field, value, onChange }: { field: MedicalFieldDefinition; value: string; onChange: (value: string) => void }) {
    switch (field.type.value) {
        case 'long_text':
            return <TextArea value={value} onChange={(event) => onChange(event.target.value)} maxLength={LONG_TEXT_MAX} rows={3} />;
        case 'choice':
            return (
                <SelectInput value={value} onChange={(event) => onChange(event.target.value)}>
                    <option value="">Not recorded</option>
                    {field.options.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </SelectInput>
            );
        case 'date':
            return <TextInput type="date" value={value} onChange={(event) => onChange(event.target.value)} />;
        case 'yes_no':
            return (
                <SelectInput value={value} onChange={(event) => onChange(event.target.value)}>
                    <option value="">Not recorded</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </SelectInput>
            );
        default:
            return <TextInput value={value} onChange={(event) => onChange(event.target.value)} maxLength={TEXT_MAX} autoComplete="off" />;
    }
}
