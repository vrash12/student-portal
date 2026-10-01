import { Head, useForm, usePage } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { BuilderSteps } from '@/components/examinations/builder-steps';
import { EmptyState } from '@/components/ui/empty-state';
import { CheckboxField, FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { examinationRoutes } from '@/lib/examination-routes';
import { terms } from '@/lib/terminology';

interface Offering {
    id: number;
    subject: { name: string };
    class_batch: { name: string };
}

interface Settings {
    kind: string;
    title: string;
    description: string;
    duration_minutes: string;
    attempt_limit: string;
    passing_score: string;
    access_code: string;
    opens_at: string;
    closes_at: string;
    release_results: boolean;
    randomize_questions: boolean;
    randomize_choices: boolean;
    question_draw_count: string;
    one_question_at_a_time: boolean;
    allow_back_navigation: boolean;
    auto_submit: boolean;
}

interface Exam extends Omit<Settings, 'duration_minutes' | 'attempt_limit' | 'passing_score' | 'question_draw_count'> {
    question_draw_count: number | null;
    id: number;
    class_subject_id: number;
    duration_minutes: number | null;
    attempt_limit: number;
    passing_score: string | null;
}

const toggles = {
    randomize_questions: 'Randomize question order',
    randomize_choices: 'Randomize multiple-choice options',
    one_question_at_a_time: 'Show one question at a time',
    allow_back_navigation: 'Allow returning to previous questions',
    auto_submit: 'Automatically submit saved answers when time expires',
    release_results: 'Release results to candidates after submission',
} as const;

type ToggleField = keyof typeof toggles;

/** Every field with its own control; errors for any other key are shown in the form's Alert. */
const FIELD_KEYS = new Set<string>([
    'class_subject_id',
    'kind',
    'title',
    'description',
    'duration_minutes',
    'attempt_limit',
    'passing_score',
    'question_draw_count',
    'access_code',
    'opens_at',
    'closes_at',
    ...Object.keys(toggles),
]);

export default function ExaminationCreate({ offerings, examination }: { offerings: Offering[]; examination?: Exam }) {
    const { app } = usePage().props;
    const form = useForm({
        class_subject_id: String(examination?.class_subject_id ?? offerings[0]?.id ?? ''),
        kind: examination?.kind ?? 'examination',
        title: examination?.title ?? '',
        description: examination?.description ?? '',
        duration_minutes: String(examination?.duration_minutes ?? '30'),
        attempt_limit: String(examination?.attempt_limit ?? 1),
        passing_score: examination?.passing_score ?? '',
        access_code: examination?.access_code ?? '',
        opens_at: examination?.opens_at ?? '',
        closes_at: examination?.closes_at ?? '',
        release_results: examination?.release_results ?? false,
        randomize_questions: examination?.randomize_questions ?? false,
        randomize_choices: examination?.randomize_choices ?? false,
        question_draw_count: examination?.question_draw_count != null ? String(examination.question_draw_count) : '',
        one_question_at_a_time: examination?.one_question_at_a_time ?? false,
        allow_back_navigation: examination?.allow_back_navigation ?? true,
        auto_submit: examination?.auto_submit ?? true,
    });

    const cancelHref = examination ? examinationRoutes.show(examination.id) : examinationRoutes.index();
    const pageTitle = examination ? 'Examination Settings' : 'Create Examination';
    const breadcrumbs: BreadcrumbItem[] = examination
        ? [
              { label: 'Examinations', href: examinationRoutes.index() },
              { label: examination.title, href: examinationRoutes.show(examination.id) },
              { label: 'Edit Settings' },
          ]
        : [{ label: 'Examinations', href: examinationRoutes.index() }, { label: 'Create Examination' }];
    const otherErrors = Object.entries(form.errors)
        .filter(([key, message]) => !FIELD_KEYS.has(key) && message !== undefined)
        .map(([, message]) => message);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (examination) {
            form.transform(({ class_subject_id: _offering, ...data }) => data);
            form.put(examinationRoutes.update(examination.id));
        } else {
            form.post(examinationRoutes.store());
        }
    };

    return (
        <>
            <Head title={pageTitle} />
            <PageHeader title={pageTitle} description="Save a draft, select questions, then review before publishing." breadcrumbs={breadcrumbs} />
            {offerings.length > 0 && <BuilderSteps current="settings" examinationId={examination?.id} />}

            {offerings.length === 0 ? (
                <div className="max-w-4xl rounded-xl border border-line bg-surface">
                    <EmptyState
                        icon={BookOpen}
                        title="You are not assigned to any subject yet"
                        description={`Examinations belong to a subject of a ${terms.classBatch.singular.toLowerCase()}. An administrator must assign you to teach a subject before you can create one.`}
                        action={
                            <ButtonLink href={examinationRoutes.index()} variant="secondary">
                                Back to Examinations
                            </ButtonLink>
                        }
                    />
                </div>
            ) : (
                <form onSubmit={submit} className="max-w-4xl space-y-6">
                    {otherErrors.length > 0 && (
                        <Alert tone="danger" title="The examination could not be saved">
                            <ul className="space-y-1">
                                {otherErrors.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </Alert>
                    )}

                    <FormSection title="Details" description="What candidates will take and where it belongs.">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label={`${terms.classBatch.singular} and subject`} required error={form.errors.class_subject_id}>
                                <SelectInput
                                    value={form.data.class_subject_id}
                                    disabled={!!examination}
                                    onChange={(event) => form.setData('class_subject_id', event.target.value)}
                                >
                                    <option value="">Choose assignment</option>
                                    {offerings.map((offering) => (
                                        <option key={offering.id} value={offering.id}>
                                            {offering.subject.name} · {offering.class_batch.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Type" required error={form.errors.kind}>
                                <SelectInput value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                                    <option value="examination">Examination</option>
                                    <option value="quiz">Quiz</option>
                                </SelectInput>
                            </FormField>
                            <FormField label="Title" className="sm:col-span-2" required error={form.errors.title}>
                                <TextInput maxLength={200} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                            </FormField>
                            <FormField label="Instructions" className="sm:col-span-2" error={form.errors.description}>
                                <TextArea
                                    rows={4}
                                    maxLength={10000}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection title="Schedule and Limits" description="When candidates can start, and how long and how often they can take it.">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Time limit (minutes)" hint="Required before publication." error={form.errors.duration_minutes}>
                                <TextInput
                                    type="number"
                                    min="1"
                                    max="1440"
                                    value={form.data.duration_minutes}
                                    onChange={(event) => form.setData('duration_minutes', event.target.value)}
                                />
                            </FormField>
                            <FormField label="Attempt limit" required error={form.errors.attempt_limit}>
                                <TextInput
                                    type="number"
                                    min="1"
                                    max="100"
                                    value={form.data.attempt_limit}
                                    onChange={(event) => form.setData('attempt_limit', event.target.value)}
                                />
                            </FormField>
                            <FormField label="Passing score (%)" hint="Optional; results have no pass/fail outcome when unset." error={form.errors.passing_score}>
                                <TextInput
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={form.data.passing_score}
                                    onChange={(event) => form.setData('passing_score', event.target.value)}
                                />
                            </FormField>
                            <FormField
                                label="Questions per attempt"
                                hint="Optional. Empty gives every question. A number (for example 20 of 50) gives each attempt its own random selection; all questions then need equal points."
                                error={form.errors.question_draw_count}
                            >
                                <TextInput
                                    type="number"
                                    min="1"
                                    max="500"
                                    value={form.data.question_draw_count}
                                    onChange={(event) => form.setData('question_draw_count', event.target.value)}
                                />
                            </FormField>
                            <FormField label="Available from" hint={`Institution time (${app.timezone}).`} error={form.errors.opens_at}>
                                <TextInput type="datetime-local" value={form.data.opens_at} onChange={(event) => form.setData('opens_at', event.target.value)} />
                            </FormField>
                            <FormField label="Available until" hint="Attempts end at the earlier of this time or their time limit." error={form.errors.closes_at}>
                                <TextInput type="datetime-local" value={form.data.closes_at} onChange={(event) => form.setData('closes_at', event.target.value)} />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection title="Delivery" description="How candidates start, see, and move through the questions.">
                        <FormField
                            label="Access code"
                            hint="Optional, at least 4 characters. Candidates must enter this to start."
                            error={form.errors.access_code}
                            className="sm:max-w-sm"
                        >
                            <TextInput
                                autoComplete="off"
                                maxLength={100}
                                value={form.data.access_code}
                                onChange={(event) => form.setData('access_code', event.target.value)}
                            />
                        </FormField>
                        <fieldset className="space-y-3">
                            <legend className="mb-2 text-sm font-medium text-ink">Delivery settings</legend>
                            {(Object.entries(toggles) as [ToggleField, string][]).map(([field, label]) => (
                                <CheckboxField
                                    key={field}
                                    label={label}
                                    checked={form.data[field]}
                                    onChange={(event) => form.setData(field, event.target.checked)}
                                    error={form.errors[field]}
                                    className="pointer-coarse:min-h-11 pointer-coarse:justify-center"
                                />
                            ))}
                        </fieldset>
                    </FormSection>

                    <FormActions>
                        <ButtonLink href={cancelHref} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            {examination ? 'Save Settings' : 'Save and Choose Questions'}
                        </Button>
                    </FormActions>
                </form>
            )}
        </>
    );
}
