import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { RosterRow } from '@/components/grading/score-sheet';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';

interface CorrectionFormData {
    candidate_id: number;
    score: string;
    comment: string;
    reason: string;
}

interface CorrectionDialogProps {
    assessmentId: number;
    maxScore: string;
    /**
     * The row being corrected, taken from the latest page props, or null
     * when the dialog is closed.
     */
    row: RosterRow | null;
    onClose: () => void;
}

/**
 * Deliberate correction of one finalized score (UI_UX_DESIGN.md §93).
 * A reason is required and is kept with the change history.
 */
export function CorrectionDialog({ assessmentId, maxScore, row, onClose }: CorrectionDialogProps) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog
            open={row !== null}
            title="Correct Finalized Score"
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
    row: RosterRow;
    onProcessingChange: (processing: boolean) => void;
    onClose: () => void;
}

function CorrectionForm({ assessmentId, maxScore, row, onProcessingChange, onClose }: CorrectionFormProps) {
    // The saved values this correction starts from. They are sent as the
    // expected values, so a change made by someone else meanwhile is caught.
    const [base, setBase] = useState({ score: row.score, comment: row.comment });
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const form = useForm<CorrectionFormData>({
        candidate_id: row.candidate.id,
        score: row.score ?? '',
        comment: row.comment ?? '',
        reason: '',
    });

    const changedMeanwhile = base.score !== row.score || base.comment !== row.comment;

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
        form.post(routes.assessments.corrections(assessmentId), {
            preserveScroll: true,
            onStart: () => {
                onProcessingChange(true);
                setConnectionError(null);
            },
            onFinish: () => onProcessingChange(false),
            onSuccess: () => onClose(),
            onNetworkError: () => {
                setConnectionError('The correction was not saved because the connection was interrupted. Check the connection and try again.');

                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
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
                        It was {base.score ?? 'no score'} when you opened this form and is now {row.score ?? 'no score'}. Review your correction
                        against the current value before saving.
                    </p>
                    <Button variant="secondary" size="sm" className="mt-2" onClick={() => setBase({ score: row.score, comment: row.comment })}>
                        Continue With Current Value
                    </Button>
                </Alert>
            )}
            {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}
            {form.errors.candidate_id && <Alert tone="danger">{form.errors.candidate_id}</Alert>}

            <FormField label={`New Score (of ${maxScore})`} error={form.errors.score} hint="Leave empty to mark the score as missing.">
                <TextInput
                    value={form.data.score}
                    onChange={(event) => form.setData('score', event.target.value)}
                    inputMode="decimal"
                    autoComplete="off"
                    className="w-32 tabular-nums"
                />
            </FormField>

            <FormField label="Comment" error={form.errors.comment} hint="Optional. Visible to instructors of this subject.">
                <TextInput
                    value={form.data.comment}
                    onChange={(event) => form.setData('comment', event.target.value)}
                    maxLength={500}
                    autoComplete="off"
                />
            </FormField>

            <FormField label="Reason for Correction" required error={form.errors.reason} hint="Kept permanently in the change history.">
                <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={500} rows={3} />
            </FormField>

            <div className="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                    Cancel
                </Button>
                <Button type="submit" loading={form.processing} disabled={changedMeanwhile}>
                    Save Correction
                </Button>
            </div>
        </form>
    );
}
