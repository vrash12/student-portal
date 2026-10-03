import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Download, X } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink, buttonClasses } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { Dialog } from '@/components/ui/dialog';
import { FormField, TextArea } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { GradeCorrectionDetails } from '@/types/grade-corrections';

/** Same limit as GradeCorrectionService::NOTE_MAX. */
const NOTE_MAX = 1000;

interface GradeCorrectionShowProps {
    correction: GradeCorrectionDetails;
    can: {
        /** May approve or reject (pending, and not the user's own request). */
        decide: boolean;
        /** May withdraw it (pending, the user's own request). */
        cancel: boolean;
        openAssessment: boolean;
        ownRequest: boolean;
    };
}

type Decision = 'approve' | 'reject';

/**
 * One grade correction request: the incident report, the requested change,
 * and the decision. Administrators approve or reject it here; the requester
 * may cancel it while it is pending. Prints as an incident report.
 */
export default function GradeCorrectionShow({ correction, can }: GradeCorrectionShowProps) {
    const formatDate = useDateFormatter();
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [decision, setDecision] = useState<Decision | null>(null);
    const pending = correction.status.value === 'pending';
    const max = correction.assessment.maxScore;
    // The score changed after the request was filed: approval will be refused.
    const changedSinceFiling = pending && (correction.scoreNow !== correction.currentScore || correction.commentNow !== correction.currentComment);

    return (
        <>
            <Head title={`Correction Request #${correction.id}`} />

            <PageHeader
                title={`Correction Request #${correction.id}`}
                description={`${correction.candidate.name} · ${correction.assessment.title}`}
                breadcrumbs={[{ label: 'Grade Corrections', href: routes.gradeCorrections.index() }, { label: `Request #${correction.id}` }]}
                actions={
                    <>
                        <a href={routes.gradeCorrections.pdf(correction.id)} className={buttonClasses('secondary')}>
                            <Download className="size-4" aria-hidden="true" />
                            Save Incident Report as PDF
                        </a>
                        {can.openAssessment && <ButtonLink href={routes.assessments.show(correction.assessment.id)}>Open Assessment</ButtonLink>}
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {errors.decision !== undefined && (
                    <Alert tone="danger" title="Nothing was changed">
                        {errors.decision}
                    </Alert>
                )}
                {changedSinceFiling && (
                    <Alert tone="warning" title="The score changed after filing">
                        It was {correction.currentScore ?? 'no score'} and is now {correction.scoreNow ?? 'no score'}. This request cannot be approved; reject
                        it so a new one can be filed.
                    </Alert>
                )}
                {pending && can.ownRequest && !can.cancel && (
                    <Alert tone="info">You filed this request; another administrator must decide it.</Alert>
                )}

                <Panel
                    title="Requested Change"
                    actions={<StatusBadge tone={correction.status.tone}>{correction.status.label}</StatusBadge>}
                >
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Detail label="Candidate">
                                {correction.candidate.name}
                                <span className="block text-sm font-normal text-ink-muted">{correction.candidate.number}</span>
                            </Detail>
                            <Detail label="Assessment">
                                {correction.assessment.title}
                                <span className="block text-sm font-normal text-ink-muted">
                                    {correction.assessment.subject} · {correction.assessment.className}
                                </span>
                            </Detail>
                            <Detail label="Requested By">
                                {correction.requestedBy}
                                <span className="block text-sm font-normal text-ink-muted">{formatDate.dateTime(correction.requestedAt)}</span>
                            </Detail>
                            <Detail label="Maximum Score">
                                <span className="tabular-nums">{max}</span>
                            </Detail>
                        </dl>

                        <div className="grid grid-cols-2 gap-3">
                            <ScoreBox label="Score When Filed" score={correction.currentScore} comment={correction.currentComment} max={max} />
                            <ScoreBox label="Requested Score" score={correction.proposedScore} comment={correction.proposedComment} max={max} emphasis />
                        </div>
                    </div>
                </Panel>

                <Panel title="Incident Report">
                    <dl className="flex flex-col gap-4">
                        <Detail label="What Happened">{correction.incidentType.label}</Detail>
                        <Detail label="Details">
                            <span className="block whitespace-pre-line font-normal">{correction.incidentDetails}</span>
                        </Detail>
                    </dl>
                </Panel>

                {!pending && (
                    <Panel title="Decision">
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Detail label={correction.status.value === 'cancelled' ? 'Cancelled By' : 'Decided By'}>
                                {correction.decidedBy ?? '—'}
                                <span className="block text-sm font-normal text-ink-muted">{formatDate.dateTime(correction.decidedAt)}</span>
                            </Detail>
                            {correction.decisionNote !== null && (
                                <div className="sm:col-span-2">
                                    <Detail label={correction.status.value === 'rejected' ? 'Reason for Rejection' : 'Note'}>
                                        <span className="block whitespace-pre-line font-normal">{correction.decisionNote}</span>
                                    </Detail>
                                </div>
                            )}
                        </dl>
                    </Panel>
                )}

                {(can.decide || can.cancel) && (
                    <div className="flex flex-col-reverse gap-2 print:hidden sm:flex-row sm:justify-end">
                        {can.cancel && (
                            <ConfirmAction
                                href={routes.gradeCorrections.cancel(correction.id)}
                                method="post"
                                variant="ghost"
                                size="md"
                                title={`Cancel request #${correction.id}?`}
                                description={<p>The score stays as it is.</p>}
                                confirmLabel="Cancel Request"
                            >
                                Cancel Request
                            </ConfirmAction>
                        )}
                        {can.decide && (
                            <>
                                <Button variant="secondary" icon={<X className="size-4" aria-hidden="true" />} onClick={() => setDecision('reject')}>
                                    Reject
                                </Button>
                                <Button icon={<Check className="size-4" aria-hidden="true" />} onClick={() => setDecision('approve')} disabled={changedSinceFiling}>
                                    Approve Correction
                                </Button>
                            </>
                        )}
                    </div>
                )}
            </div>

            <DecisionDialog correction={correction} decision={decision} onClose={() => setDecision(null)} />
        </>
    );
}

function DecisionDialog({ correction, decision, onClose }: { correction: GradeCorrectionDetails; decision: Decision | null; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={decision !== null}
            title={decision === 'reject' ? `Reject Request #${correction.id}?` : `Approve Request #${correction.id}?`}
            description={`${correction.candidate.name} · ${correction.assessment.title}`}
            busy={processing}
            onClose={onClose}
        >
            {decision !== null && <DecisionForm key={decision} correction={correction} decision={decision} onProcessingChange={setProcessing} onClose={onClose} />}
        </Dialog>
    );
}

interface DecisionFormProps {
    correction: GradeCorrectionDetails;
    decision: Decision;
    onProcessingChange: (processing: boolean) => void;
    onClose: () => void;
}

function DecisionForm({ correction, decision, onProcessingChange, onClose }: DecisionFormProps) {
    const form = useForm({ note: '' });
    const rejecting = decision === 'reject';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(rejecting ? routes.gradeCorrections.reject(correction.id) : routes.gradeCorrections.approve(correction.id), {
            preserveScroll: true,
            onStart: () => onProcessingChange(true),
            onFinish: () => onProcessingChange(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            <p className="text-sm text-ink">
                {rejecting ? (
                    <>The score stays at {correction.currentScore ?? 'no score'}.</>
                ) : (
                    <>
                        The score changes from <strong>{correction.currentScore ?? 'no score'}</strong> to <strong>{correction.proposedScore ?? 'no score'}</strong>.
                    </>
                )}
            </p>
            <FormField
                label={rejecting ? 'Reason for Rejection' : 'Note'}
                required={rejecting}
                error={form.errors.note}
                hint={rejecting ? 'Shown to the instructor.' : 'Optional.'}
            >
                <TextArea value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} maxLength={NOTE_MAX} rows={3} />
            </FormField>
            <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                    Go Back
                </Button>
                <Button type="submit" variant={rejecting ? 'danger' : 'primary'} loading={form.processing}>
                    {rejecting ? 'Reject Request' : 'Approve and Change Score'}
                </Button>
            </div>
        </form>
    );
}

function ScoreBox({ label, score, comment, max, emphasis = false }: { label: string; score: string | null; comment: string | null; max: string; emphasis?: boolean }) {
    return (
        <div className={emphasis ? 'rounded-lg border-2 border-primary-700 bg-primary-50 p-4' : 'rounded-lg border border-line-box bg-surface-muted p-4'}>
            <p className="text-sm text-ink-muted">{label}</p>
            <p className="mt-1 text-3xl font-bold tabular-nums text-ink">{score ?? '—'}</p>
            <p className="text-sm text-ink-muted">{score === null ? 'No score' : `of ${max}`}</p>
            {comment !== null && <p className="mt-2 text-sm text-ink">{comment}</p>}
        </div>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-ink-muted">{label}</dt>
            <dd className="mt-0.5 font-medium text-ink">{children}</dd>
        </div>
    );
}
