import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { CheckboxField, FormField, SelectInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';
import type { SubjectWeightsRow, WeightComponent, WeightsCopySource } from '@/types/grading-setup';

interface CopyWeightsDialogProps {
    open: boolean;
    sources: WeightsCopySource[];
    /** Subjects of the period without weights: the only ones that can receive them. */
    targets: SubjectWeightsRow[];
    periodId: number;
    onClose: () => void;
}

interface CopyForm {
    source: string;
    targets: number[];
    period: number;
}

/** "Quizzes 20% · Examinations 30%" */
export function componentsText(components: WeightComponent[]): string {
    return components.map((component) => `${component.name} ${component.weight}%`).join(' · ');
}

/**
 * Gives every chosen subject without weights the same components and weights
 * as one that is set up. Subjects that already have weights are never
 * changed here; the server checks this again.
 */
export function CopyWeightsDialog({ open, sources, targets, periodId, onClose }: CopyWeightsDialogProps) {
    const form = useForm<CopyForm>({ source: '', targets: [], period: periodId });
    const source = sources.find((option) => String(option.classSubjectId) === form.data.source) ?? null;
    const errors = form.errors as Record<string, string | undefined>;
    // Only subjects still offered count: after a failed copy the page reloads
    // and a subject that meanwhile got weights (or was removed) drops out.
    const offered = new Set(targets.map((row) => row.classSubjectId));
    const chosen = form.data.targets.filter((id) => offered.has(id));
    // Per-subject errors come back as targets.0, targets.1, ...
    const targetsError = Object.entries(errors).find(([key]) => key === 'targets' || key.startsWith('targets.'))?.[1];

    // Each opening starts with the first source and every empty subject chosen
    // (only when the dialog opens, not on every change of the form).
    useEffect(() => {
        if (open) {
            form.setData({ source: sources[0] === undefined ? '' : String(sources[0].classSubjectId), targets: targets.map((row) => row.classSubjectId), period: periodId });
            form.clearErrors();
        }
    }, [open]);

    const toggle = (classSubjectId: number, checked: boolean) => {
        form.setData('targets', checked ? [...form.data.targets, classSubjectId] : form.data.targets.filter((id) => id !== classSubjectId));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, targets: data.targets.filter((id) => offered.has(id)) }));
        form.post(routes.gradingSetup.copyWeights(), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={open}
            size="lg"
            busy={form.processing}
            title="Copy Weights"
            description="Give subjects without weights the same components and weights as a subject that is set up. Subjects that already have weights are changed on their own page."
            onClose={onClose}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" form="copy-weights-form" loading={form.processing} disabled={chosen.length === 0 || source === null}>
                        Copy to {chosen.length} {chosen.length === 1 ? 'Subject' : 'Subjects'}
                    </Button>
                </>
            }
        >
            <form id="copy-weights-form" onSubmit={submit} noValidate className="flex flex-col gap-5">
                <FormField label="Copy the weights of" required error={errors.source} hint={source === null ? undefined : componentsText(source.components)}>
                    <SelectInput value={form.data.source} onChange={(event) => form.setData('source', event.target.value)}>
                        {sources.map((option) => (
                            <option key={option.classSubjectId} value={String(option.classSubjectId)}>
                                {option.label}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>

                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-2 text-sm font-medium text-ink">To these subjects without weights</legend>
                    {targets.length === 0 && <p className="text-sm text-ink-muted">Every subject of this period has weights now.</p>}
                    {targets.map((row) => (
                        <CheckboxField
                            key={row.classSubjectId}
                            label={`${row.classBatch.name} · ${row.subject.name}`}
                            checked={form.data.targets.includes(row.classSubjectId)}
                            onChange={(event) => toggle(row.classSubjectId, event.target.checked)}
                        />
                    ))}
                    {targetsError !== undefined && (
                        <p className="text-sm text-danger-fg" role="alert">
                            {targetsError}
                        </p>
                    )}
                </fieldset>

                <p className="text-sm text-ink-muted">
                    Each subject keeps its own copy: changing one later does not change the others. Every copy is kept in the audit history.
                </p>
            </form>
        </Dialog>
    );
}
