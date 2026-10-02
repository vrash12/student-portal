import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Download, ExternalLink, Eye, FileDown, FileImage, FileText, Undo2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { ProtectedDocumentViewer } from '@/components/medical/protected-document-viewer';
import { Alert } from '@/components/ui/alert';
import { Button, buttonClasses } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Dialog } from '@/components/ui/dialog';
import { FormField, TextArea } from '@/components/ui/form-field';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { MedicalDocument } from '@/types/medical';

export type MedicalDocumentAudience = 'staff' | 'granted' | 'candidate';

/** "2.4 MB", "830 KB". */
export function formatFileSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

interface MedicalDocumentListProps {
    documents: MedicalDocument[];
    audience: MedicalDocumentAudience;
    /** Shown when there is no document. */
    emptyText: string;
    /** Optional extra line per document, e.g. the candidate on the review page. */
    renderContext?: (document: MedicalDocument) => ReactNode;
}

/**
 * Uploaded medical documents, ten per page, with the actions of the viewer:
 * medical staff open, download, accept or return; instructors with approved
 * access view in the protected viewer only; candidates open, download and
 * withdraw an upload that waits for review.
 */
export function MedicalDocumentList({ documents, audience, emptyText, renderContext }: MedicalDocumentListProps) {
    const pagination = useClientPagination(documents);
    const [viewing, setViewing] = useState<MedicalDocument | null>(null);
    const [returning, setReturning] = useState<MedicalDocument | null>(null);
    const [requesting, setRequesting] = useState<MedicalDocument | null>(null);
    const page = usePage();
    const viewerName = page.props.auth.user?.name ?? 'Signed-in user';
    const errors = page.props.errors as Record<string, string | undefined>;
    const documentError = errors.document ?? errors.download;

    if (documents.length === 0) {
        return <p className="text-sm text-ink-muted">{emptyText}</p>;
    }

    return (
        <div className="flex flex-col">
            {documentError !== undefined && (
                <Alert tone="danger" title="Nothing was changed" className="mb-4">
                    {documentError}
                </Alert>
            )}
            <ul className="flex flex-col gap-3">
                {pagination.rows.map((document) => (
                    <DocumentItem
                        key={document.id}
                        document={document}
                        audience={audience}
                        context={renderContext?.(document)}
                        onView={() => setViewing(document)}
                        onReturn={() => setReturning(document)}
                        onRequestDownload={() => setRequesting(document)}
                    />
                ))}
            </ul>
            <ClientPagination pagination={pagination} noun={{ one: 'document', other: 'documents' }} label="Medical document pages" />
            {audience === 'granted' && <ProtectedDocumentViewer document={viewing} viewerName={viewerName} onClose={() => setViewing(null)} />}
            {audience === 'staff' && <ReturnDialog document={returning} onClose={() => setReturning(null)} />}
            {audience === 'granted' && <RequestDownloadDialog document={requesting} onClose={() => setRequesting(null)} />}
        </div>
    );
}

interface DocumentItemProps {
    document: MedicalDocument;
    audience: MedicalDocumentAudience;
    context?: ReactNode;
    onView: () => void;
    onReturn: () => void;
    onRequestDownload: () => void;
}

function DocumentItem({ document, audience, context, onView, onReturn, onRequestDownload }: DocumentItemProps) {
    const formatDate = useDateFormatter();
    const Icon = document.fileType === 'pdf' ? FileText : FileImage;
    const returned = document.status.value === 'returned';

    return (
        <li className="flex flex-col gap-3 rounded-lg border border-line-box bg-surface px-4 py-3 sm:flex-row sm:items-start">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700" aria-hidden="true">
                <Icon className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold text-ink">{document.title}</p>
                    {audience !== 'granted' && <StatusBadge tone={document.status.tone}>{document.status.label}</StatusBadge>}
                </div>
                {context}
                <p className="mt-0.5 text-sm text-ink-muted">
                    {document.category.label}
                    {document.documentDate !== null && ` · dated ${formatCalendarDate(document.documentDate)}`}
                    {` · ${document.fileType === 'pdf' ? 'PDF' : 'Photo'}, ${formatFileSize(document.sizeBytes)}`}
                    {document.uploadedAt !== null && ` · uploaded ${formatDate.dateTime(document.uploadedAt)}`}
                </p>
                {document.notes !== null && <p className="mt-1 text-sm whitespace-pre-line text-ink">{document.notes}</p>}
                {returned && document.reviewNote !== null && (
                    <p className="mt-2 rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-fg">
                        <span className="font-semibold">Returned{document.reviewedBy ? ` by ${document.reviewedBy}` : ''}:</span> {document.reviewNote}
                        {audience === 'candidate' && ' Upload a corrected document.'}
                    </p>
                )}
                {!returned && document.status.value === 'accepted' && audience !== 'granted' && (
                    <p className="mt-1 text-xs text-ink-muted">
                        Accepted{document.reviewedBy ? ` by ${document.reviewedBy}` : ''}
                        {document.reviewedAt !== null && `, ${formatDate.dateTime(document.reviewedAt)}`}
                        {document.reviewNote !== null && ` · “${document.reviewNote}”`}
                    </p>
                )}
                {document.download !== null && <DownloadStatus download={document.download} />}
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                {audience === 'granted' ? (
                    <>
                        <Button variant="secondary" size="sm" onClick={onView} icon={<Eye className="size-4" aria-hidden="true" />}>
                            View
                        </Button>
                        {document.download?.fileUrl && (
                            <a href={document.download.fileUrl} className={buttonClasses('primary', 'sm')}>
                                <FileDown className="size-4" aria-hidden="true" />
                                Download<span className="sr-only"> {document.title}</span>
                            </a>
                        )}
                        {document.download?.canRequest && (
                            <Button variant="ghost" size="sm" onClick={onRequestDownload} icon={<FileDown className="size-4" aria-hidden="true" />}>
                                Request Download
                            </Button>
                        )}
                        {document.download?.canCancel && document.download.request !== null && (
                            <ConfirmAction
                                href={routes.medical.downloads.cancel(document.download.request.id)}
                                method="post"
                                variant="ghost"
                                size="sm"
                                title="Cancel the download request?"
                                description={<p>The medical staff will no longer see it. You can request a download again later.</p>}
                                confirmLabel="Cancel Request"
                            >
                                Cancel Request
                            </ConfirmAction>
                        )}
                    </>
                ) : (
                    <>
                        {document.fileUrl !== null && (
                            <a href={document.fileUrl} target="_blank" rel="noopener" className={buttonClasses('secondary', 'sm')}>
                                <ExternalLink className="size-4" aria-hidden="true" />
                                Open<span className="sr-only"> {document.title} in a new tab</span>
                            </a>
                        )}
                        {document.downloadUrl !== null && (
                            <a href={document.downloadUrl} className={buttonClasses('ghost', 'sm')} aria-label={`Download ${document.title}`}>
                                <Download className="size-4" aria-hidden="true" />
                            </a>
                        )}
                    </>
                )}
                {document.can.review && (
                    <>
                        <Button
                            size="sm"
                            icon={<Check className="size-4" aria-hidden="true" />}
                            onClick={() => router.post(routes.medical.documents.accept(document.id), {}, { preserveScroll: true })}
                        >
                            Accept
                        </Button>
                        <Button variant="secondary" size="sm" icon={<Undo2 className="size-4" aria-hidden="true" />} onClick={onReturn}>
                            Return
                        </Button>
                    </>
                )}
                {document.can.withdraw && (
                    <ConfirmAction
                        href={routes.portal.medicalDocuments.destroy(document.id)}
                        method="delete"
                        variant="ghost"
                        size="sm"
                        title="Withdraw this document?"
                        description={<p>It is removed before the medical staff review it. You can upload it again later.</p>}
                        confirmLabel="Withdraw"
                    >
                        Withdraw
                    </ConfirmAction>
                )}
            </div>
        </li>
    );
}

/** The instructor's latest download request for a document, in one line. */
function DownloadStatus({ download }: { download: NonNullable<MedicalDocument['download']> }) {
    const formatDate = useDateFormatter();
    const request = download.request;
    if (request === null || request.status.value === 'cancelled') {
        return null;
    }

    const text = {
        pending: 'Download requested · waiting for the medical staff',
        approved: `Download approved${request.expiresAt !== null ? ` until ${formatDate.dateTime(request.expiresAt)}` : ''}${request.decidedBy ? ` by ${request.decidedBy}` : ''}`,
        rejected: `Download request rejected${request.decidedBy ? ` by ${request.decidedBy}` : ''}${request.decisionNote ? `: “${request.decisionNote}”` : ''}`,
        expired: 'Your approved download has ended',
        revoked: 'Your approved download was withdrawn',
    }[request.status.value];

    return text === undefined ? null : (
        <p className="mt-2 flex flex-wrap items-center gap-2 text-sm text-ink">
            <StatusBadge tone={request.status.tone}>{request.status.label}</StatusBadge>
            <span className="text-ink-muted">{text}</span>
        </p>
    );
}

/** An instructor asks the medical staff for a copy of a document, with a reason. */
function RequestDownloadDialog({ document, onClose }: { document: MedicalDocument | null; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (document === null) {
            return;
        }
        form.post(routes.medical.downloads.store(document.id), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={document !== null} title="Request a Download" description={document?.title} busy={form.processing} onClose={close}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                <Alert tone="info">
                    The medical staff decide. If they approve, you can download this document for a few days from this list. Every download is recorded.
                </Alert>
                <FormField label="Why do you need a copy?" required error={form.errors.reason} hint="At least 20 characters. The medical staff read this.">
                    <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={1000} rows={4} />
                </FormField>
                <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                    <Button variant="secondary" onClick={close} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Send Request
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

/** Returns a document to the candidate with a reason they will see. */
function ReturnDialog({ document, onClose }: { document: MedicalDocument | null; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (document === null) {
            return;
        }
        form.post(routes.medical.documents.return(document.id), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={document !== null} title="Return this document?" busy={form.processing} onClose={close}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                <Alert tone="info">The candidate sees your reason and can upload a corrected document. The returned file stays on record.</Alert>
                <FormField label="Reason" required error={form.errors.reason} hint="For example: “The photo is blurred; please upload a clearer copy.” At least 5 characters.">
                    <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={500} rows={3} />
                </FormField>
                <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                    <Button variant="secondary" onClick={close} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" loading={form.processing}>
                        Return Document
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
