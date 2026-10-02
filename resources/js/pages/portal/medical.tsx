import { Head, useForm } from '@inertiajs/react';
import { ClipboardList, FileStack, HeartPulse, Upload } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { MedicalDocumentList } from '@/components/medical/medical-documents';
import { MedicalEntries } from '@/components/medical/medical-record-panel';
import { PortalHeading, PortalSection } from '@/components/portal/portal-ui';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileInput, FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';
import type { MedicalDocument, MedicalEntry } from '@/types/medical';

interface PortalMedicalProps {
    documents: MedicalDocument[];
    /** Record fields the medical staff share with candidates. */
    fields: MedicalEntry[];
    categories: Array<{ value: string; label: string }>;
    limits: { maxMb: number; maxDocuments: number; remaining: number };
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

interface UploadForm {
    category: string;
    title: string;
    document_date: string;
    notes: string;
    file: File | null;
}

/**
 * The candidate's medical records (owner request, 2026-10-02): upload
 * medical certificates, check-up findings and similar documents, follow
 * their review, withdraw an upload that waits for review, and read the
 * fields the medical staff share with candidates.
 */
export default function PortalMedical({ documents, fields, categories, limits, today }: PortalMedicalProps) {
    const fileRef = useRef<HTMLInputElement>(null);
    const form = useForm<UploadForm>({ category: '', title: '', document_date: '', notes: '', file: null });
    const waiting = documents.filter((document) => document.status.value === 'submitted').length;
    const returned = documents.filter((document) => document.status.value === 'returned').length;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.portal.medicalDocuments.store(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (fileRef.current !== null) {
                    fileRef.current.value = '';
                }
            },
        });
    };

    return (
        <>
            <Head title="My Medical Records" />
            <PortalHeading
                icon={HeartPulse}
                title="My Medical Records"
                description="Upload your medical certificates and the findings of every medical check-up. Only the medical staff see them; an instructor sees them only with the medical staff's approval, view only."
            />

            <div className="flex flex-col gap-8">
                <PortalSection icon={Upload} title="Upload a Document" description={`PDF or a photo (JPEG, PNG or WebP), up to ${limits.maxMb} MB. The medical staff review each document.`}>
                    {limits.remaining === 0 ? (
                        <Alert tone="warning">You have reached the limit of {limits.maxDocuments} documents. Ask the medical staff for help.</Alert>
                    ) : (
                        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <FormField label="Kind of document" required error={form.errors.category}>
                                    <SelectInput value={form.data.category} onChange={(event) => form.setData('category', event.target.value)}>
                                        <option value="">Choose…</option>
                                        {categories.map((category) => (
                                            <option key={category.value} value={category.value}>
                                                {category.label}
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>
                                <FormField label="Date on the document" error={form.errors.document_date} hint="Optional. The date of the check-up or certificate.">
                                    <TextInput type="date" max={today} value={form.data.document_date} onChange={(event) => form.setData('document_date', event.target.value)} />
                                </FormField>
                            </div>
                            <FormField label="Title" required error={form.errors.title} hint="For example: Annual physical examination findings.">
                                <TextInput value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} maxLength={150} autoComplete="off" />
                            </FormField>
                            <FormField label="Notes" error={form.errors.notes} hint="Optional. Anything the medical staff should know about this document.">
                                <TextArea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} rows={2} />
                            </FormField>
                            <FormField label="File" required error={form.errors.file} hint="On a tablet you can take a photo of the paper document.">
                                <FileInput
                                    ref={fileRef}
                                    accept="application/pdf,image/jpeg,image/png,image/webp"
                                    onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                />
                            </FormField>
                            {form.progress !== null && form.progress?.percentage !== undefined && (
                                <div className="flex items-center gap-3" role="status">
                                    <span className="h-2 flex-1 overflow-hidden rounded-full bg-surface-muted">
                                        <span className="block h-full rounded-full bg-primary-600" style={{ width: `${form.progress.percentage}%` }} />
                                    </span>
                                    <span className="text-sm tabular-nums text-ink-muted">Uploading {form.progress.percentage}%</span>
                                </div>
                            )}
                            <div className="flex justify-end">
                                <Button type="submit" size="lg" loading={form.processing} icon={<Upload className="size-5" aria-hidden="true" />}>
                                    Upload Document
                                </Button>
                            </div>
                        </form>
                    )}
                </PortalSection>

                <PortalSection
                    icon={FileStack}
                    title="My Uploaded Documents"
                    description={
                        documents.length === 0
                            ? 'Nothing uploaded yet.'
                            : `${documents.length} ${documents.length === 1 ? 'document' : 'documents'}${waiting > 0 ? ` · ${waiting} waiting for review` : ''}${returned > 0 ? ` · ${returned} returned (see the reason)` : ''}`
                    }
                >
                    <MedicalDocumentList documents={documents} audience="candidate" emptyText="Upload your first document above, for example your medical certificate." />
                </PortalSection>

                {fields.length > 0 && (
                    <PortalSection icon={ClipboardList} title="Recorded by the Medical Staff" description="Ask the clinic or the academic office to correct anything.">
                        <MedicalEntries entries={fields} />
                    </PortalSection>
                )}
            </div>
        </>
    );
}
