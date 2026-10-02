import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { RosterRow } from '@/components/grading/score-sheet';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';
import type { IncidentTypeOption } from '@/types/grade-corrections';

/** Same limits as GradeCorrectionService. */
const DETAILS_MIN = 20;
const DETAILS_MAX = 2000;

interface CorrectionFormData {
    candidate_id: number;
    score: string;
    comment: string;
    incident_type: string;
    incident_details: string;
}

interface CorrectionDialogProps {
    assessmentId: number;
    maxScore: string;
    incidentTypes: IncidentTypeOption[];
    /**
     * The row being corrected, taken from the latest page props, or null
     * when the dialog is closed.
     */
    row: RosterRow | null;
    onClose: () => void;
}

/**
 * Request to correct one finalized score (owner request, 2026-10-02). The
 * score does not change here: an administrator must approve the request and
 * its incident report first.
 */
export function CorrectionDialog({ assessmentId, maxScore, incidentTypes, row, onClose }: CorrectionDialogProps) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={row !== null}
            title="Request a Score Correction"
            description={row === null ? undefined : `${row.candidate.name} · Candidate ${row.candidate.candidateNumber}`}
            busy={processing}
            onClose={onClose}
        >
            {/* Mounted once per opened row, so the values it started from stay fixed. */}
            {row !== null && (
                <CorrectionForm
                    key={row.candidate.id}
                    assessmentId={assessmentId}
                    maxScore={maxScore}
                    incidentTypes={incidentTypes}
                    row={row}
                    onProcessingChange={setProcessing}
                    onClose={onClose}
                />
            )}
        </Dialog>
    );
}

interface CorrectionFormProps {
    assessmentId: number;
    maxScore: string;
    incidentTypes: IncidentTypeOption[];
    row: RosterRow;
    onProcessingChange: (processing: boolean) => void;
    onClose: () => void;
}

function CorrectionForm({ assessmentId, maxScore, incidentTypes, row, onProcessingChange, onClose }: CorrectionFormProps) {
    // The saved values this request starts from. They are sent as the
    // expected values, so a change made by someone else meanwhile is caught.
    const [base, setBase] = useState({ score: row.score, comment: row.comment });
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const form = useForm<CorrectionFormData>({
        candidate_id: row.candidate.id,
        score: row.score ?? '',
        comment: row.comment ?? '',
        incident_type: '',
        incident_details: '',
    });

    const changedMeanwhile = base.score !== row.score || base.comment !== row.comment;
    const selectedType = incidentTypes.find((type) => type.value === form.data.incident_type);
    const detailsLength = form.data.incident_details.trim().length;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (changedMeanwhile) {
            return;
        }

        form.transform((data) => ({
            ...data,
            score: data.score.trim() === '' ? null : data.score.trim(),
            comment: data.comment.trim() === '' ? null : data.comment.trim(),
            expected_score: base.score,
            expected_comment: base.comment,
        }));
        form.post(routes.assessments.correctionRequests(assessmentId), {
            preserveScroll: true,
            onStart: () => {
                onProcessingChange(true);
                setConnectionError(null);
            },
            onFinish: () => onProcessingChange(false),
            onSuccess: () => onClose(),
            onNetworkError: () => {
                setConnectionError('The request was not sent because the connection was interrupted. Check the connection and try again.');

                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            <Alert tone="info">The score does not change yet. An administrator reviews your incident report and approves or rejects the correction.</Alert>

            <dl className="grid grid-cols-2 gap-4 rounded-lg border border-line bg-surface-muted px-4 py-3 text-sm">
                <div>
                    <dt className="text-ink-muted">Current Score</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-ink">{row.score === null ? 'No score' : `${row.score} of ${maxScore}`}</dd>
                </div>
                <div>
                    <dt className="text-ink-muted">Current Comment</dt>
                    <dd className="mt-0.5 text-ink">{row.comment ?? '—'}</dd>
                </div>
            </dl>

            {changedMeanwhile && (
                <Alert tone="warning" title="Another user changed this score">
                    <p>
                        It was {base.score ?? 'no score'} when you opened this form and is now {row.score ?? 'no score'}. Review your request against
                        the current value before sending it.
                    </p>
                    <Button variant="secondary" size="sm" className="mt-2" onClick={() => setBase({ score: row.score, comment: row.comment })}>
                        Continue With Current Value
                    </Button>
                </Alert>
            )}
            {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}
            {form.errors.candidate_id && <Alert tone="danger">{form.errors.candidate_id}</Alert>}

            <div className="grid gap-5 sm:grid-cols-[10rem_minmax(0,1fr)]">
                <FormField label={`Correct Score (of ${maxScore})`} error={form.errors.score} hint="Empty = missing.">
                    <TextInput
                        value={form.data.score}
                        onChange={(event) => form.setData('score', event.target.value)}
                        inputMode="decimal"
                        autoComplete="off"
                        className="tabular-nums"
                    />
                </FormField>
                <FormField label="Comment" error={form.errors.comment} hint="Optional. Visible to instructors of this subject.">
                    <TextInput value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} maxLength={500} autoComplete="off" />
                </FormField>
            </div>

            <fieldset className="flex flex-col gap-5 rounded-lg border border-line px-4 pt-3 pb-4">
                <legend className="px-1 text-sm font-semibold text-ink">Incident Report</legend>
                <FormField label="What happened?" required error={form.errors.incident_type} hint={selectedType?.description}>
                    <SelectInput value={form.data.incident_type} onChange={(event) => form.setData('incident_type', event.target.value)}>
                        <option value="">Choose one</option>
                        {incidentTypes.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
                <FormField
                    label="Details"
                    required
                    error={form.errors.incident_details}
                    hint={`How the mistake happened, how it was found, and why this is the correct score. At least ${DETAILS_MIN} characters (${detailsLength} so far). Kept permanently.`}
                >
                    <TextArea
                        value={form.data.incident_details}
                        onChange={(event) => form.setData('incident_details', event.target.value)}
                        maxLength={DETAILS_MAX}
                        rows={5}
                    />
                </FormField>
            </fieldset>

            <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button type="submit" loading={form.processing} disabled={changedMeanwhile}>
                    Send for Approval
                </Button>
            </div>
        </form>
    );
}
