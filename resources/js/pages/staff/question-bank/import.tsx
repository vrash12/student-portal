import { Head, useForm } from '@inertiajs/react';
import { ClipboardList, Download, Upload } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink, buttonClasses } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { FileInput, FormField, SelectInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { Table, TableBody, TableHead, Td, Th } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';
import { routes } from '@/lib/routes';
import type { QuestionBankSubject } from '@/types/question-bank';

interface ImportLimits {
    maxRows: number;
    maxFileKilobytes: number;
}

interface ImportQuestionsProps {
    /** The subjects the user teaches; questions can only be imported into these. */
    subjects: QuestionBankSubject[];
    /** From ?subject=, or the only subject the user teaches. */
    selectedSubjectId: number | null;
    limits: ImportLimits;
}

interface ImportFormData {
    subject_id: string;
    file: File | null;
}

/** A problem in one cell (or the whole row) of the uploaded file. */
interface RowProblem {
    row: number;
    column: string;
    message: string;
}

/** CSV columns in file order; "row" is a problem with the whole row. */
const COLUMN_ORDER = ['type', 'question', 'choice_a', 'choice_b', 'choice_c', 'choice_d', 'choice_e', 'choice_f', 'correct', 'points', 'topic', 'explanation', 'row'];

const ROW_PROBLEM_KEY = /^rows\.(\d+)\.([a-z_]+)$/;

const COLUMN_REFERENCE: Array<{ name: string; required: boolean; description: string }> = [
    { name: 'type', required: true, description: 'multiple_choice, true_false, or essay. MC, TF, and Essay also work.' },
    { name: 'question', required: true, description: 'The question text. Line breaks inside a cell are kept.' },
    {
        name: 'choice_a … choice_f',
        required: false,
        description: 'Multiple choice only: 2 to 6 choices, filled from choice_a without gaps. Leave them empty for true / false and essay questions.',
    },
    {
        name: 'correct',
        required: false,
        description: 'Multiple choice: the letter of the correct choice (A to F). True / false: True or False. Essay: leave it empty.',
    },
    { name: 'points', required: false, description: '0.01 to 100. When empty, the question is worth 1 point.' },
    { name: 'topic', required: false, description: 'Optional. A topic of the subject with the same name is reused; a new name creates the topic.' },
    { name: 'explanation', required: false, description: 'Optional. Visible to staff only: why the answer is correct, or essay grading guidance.' },
];

export default function ImportQuestions({ subjects, selectedSubjectId, limits }: ImportQuestionsProps) {
    const form = useForm<ImportFormData>({
        subject_id: selectedSubjectId === null ? '' : String(selectedSubjectId),
        file: null,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const problemsHeading = useRef<HTMLHeadingElement>(null);

    // Row errors are keyed "rows.{row}.{column}", which are not form fields.
    const errors = form.errors as Partial<Record<string, string>>;
    const problems = rowProblems(errors);
    const problemPagination = useClientPagination(problems);
    const cancelHref = routes.questionBank.index(form.data.subject_id === '' ? {} : { subject: form.data.subject_id });
    const maxFileSize = limits.maxFileKilobytes >= 1024 ? `${limits.maxFileKilobytes / 1024} MB` : `${limits.maxFileKilobytes} KB`;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }

        form.post(routes.questionBank.import.store(), {
            forceFormData: true,
            preserveScroll: true,
            onError: (received) => {
                const receivedErrors = received as Partial<Record<string, string>>;
                problemPagination.setPage(1);
                // The file has to be chosen again after it is fixed (a changed file cannot be re-sent).
                form.setData('file', null);
                if (fileInput.current !== null) {
                    fileInput.current.value = '';
                }

                window.setTimeout(() => {
                    if (Object.keys(receivedErrors).some((key) => ROW_PROBLEM_KEY.test(key))) {
                        problemsHeading.current?.focus();
                    } else if (receivedErrors.subject_id !== undefined) {
                        document.querySelector<HTMLSelectElement>('select[name="subject_id"]')?.focus();
                    } else {
                        fileInput.current?.focus();
                    }
                }, 0);
            },
        });
    };

    const templateLink = (
        <a href={routes.questionBank.import.template()} download className={buttonClasses('secondary')}>
            <Download className="size-4" aria-hidden="true" />
            Download Template
        </a>
    );

    return (
        <>
            <Head title="Import Questions" />

            <div className="mx-auto max-w-4xl">
                <PageHeader
                    title="Import Questions"
                    description="Add many questions to one subject at once from a CSV file. If any row has a problem, nothing is imported."
                    breadcrumbs={[
                        { label: 'Examinations', href: examinationRoutes.index() },
                        { label: 'Question Bank', href: routes.questionBank.index() },
                        { label: 'Import Questions' },
                    ]}
                />

                {subjects.length === 0 ? (
                    <div className="rounded-lg border border-line-box bg-surface">
                        <EmptyState
                            icon={ClipboardList}
                            title="You do not teach any subjects yet"
                            description="Questions belong to a subject you teach. Once an academic administrator assigns you to teach a subject of a class, you can import questions for it."
                            action={<ButtonLink href={routes.questionBank.index()}>Back to Question Bank</ButtonLink>}
                        />
                    </div>
                ) : (
                    <div className="flex flex-col gap-6">
                        <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                            <FormSection title="Import File">
                                <FormField label="Subject" required error={errors.subject_id} hint="Only subjects you teach are listed. Every question in the file is added to this subject.">
                                    <SelectInput
                                        name="subject_id"
                                        value={form.data.subject_id}
                                        onChange={(event) => form.setData('subject_id', event.target.value)}
                                        disabled={form.processing}
                                    >
                                        <option value="">Select a subject</option>
                                        {subjects.map((subject) => (
                                            <option key={subject.id} value={String(subject.id)}>
                                                {subject.name} ({subject.code})
                                            </option>
                                        ))}
                                    </SelectInput>
                                </FormField>

                                <FormField
                                    label="CSV File"
                                    required
                                    error={errors.file}
                                    hint={`A UTF-8 CSV file with a header row, up to ${maxFileSize} and ${limits.maxRows.toLocaleString()} questions. In a spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".`}
                                >
                                    <FileInput
                                        ref={fileInput}
                                        name="file"
                                        accept=".csv,text/csv"
                                        disabled={form.processing}
                                        onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                                    />
                                </FormField>

                                <p className="text-sm text-ink-muted">
                                    Images, audio, and video cannot be imported. After importing, add them to each question from its Edit page.
                                </p>

                                {form.progress && <progress className="w-full" value={form.progress.percentage ?? 0} max={100} aria-label="Upload progress" />}
                            </FormSection>

                            {problems.length > 0 && (
                                <section aria-labelledby="import-problems-heading" className="rounded-lg border border-danger-border bg-surface">
                                    <div className="border-b border-line px-5 py-4">
                                        <h2 id="import-problems-heading" ref={problemsHeading} tabIndex={-1} className="text-base font-semibold text-ink focus:outline-none">
                                            Problems Found in the File
                                        </h2>
                                        <Alert tone="danger" title="No questions were imported" className="mt-3">
                                            {problems.length === 1 ? 'One problem needs fixing.' : `${problems.length} problems need fixing.`} Correct them in your spreadsheet, save it as CSV
                                            UTF-8 again, and choose the file again. The question bank has not been changed.
                                        </Alert>
                                    </div>
                                    <Table caption="Problems found in the file, by row and column">
                                        <TableHead>
                                            <Th className="w-20">Row</Th>
                                            <Th className="w-36">Column</Th>
                                            <Th>How to fix it</Th>
                                        </TableHead>
                                        <TableBody>
                                            {problemPagination.rows.map((problem) => (
                                                <tr key={`${problem.row}-${problem.column}`}>
                                                    <Td numeric>{problem.row}</Td>
                                                    <Td>{problem.column === 'row' ? 'Whole row' : <code className="text-sm">{problem.column}</code>}</Td>
                                                    <Td className="break-words">{problem.message}</Td>
                                                </tr>
                                            ))}
                                        </TableBody>
                                    </Table>
                                    <ClientPagination pagination={problemPagination} noun={{ one: 'problem', other: 'problems' }} label="Problem pages" />
                                </section>
                            )}

                            <FormActions>
                                <ButtonLink href={cancelHref}>Cancel</ButtonLink>
                                <Button type="submit" icon={<Upload className="size-4" aria-hidden="true" />} loading={form.processing}>
                                    {form.processing ? 'Importing…' : 'Import Questions'}
                                </Button>
                            </FormActions>
                        </form>

                        <section aria-labelledby="import-format-heading" className="rounded-lg border border-line-box bg-surface">
                            <div className="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 id="import-format-heading" className="text-base font-semibold text-ink">
                                        File Format
                                    </h2>
                                    <p className="mt-0.5 text-sm text-ink-muted">
                                        One question per row below the header row. Column names can be in any order and any capitals. The template has one example of each
                                        question type.
                                    </p>
                                </div>
                                <div className="shrink-0">{templateLink}</div>
                            </div>
                            <dl className="divide-y divide-line">
                                {COLUMN_REFERENCE.map((column) => (
                                    <div key={column.name} className="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:gap-6">
                                        <dt className="text-sm font-medium text-ink sm:w-48 sm:shrink-0">
                                            <code>{column.name}</code>
                                            {column.required && <span className="ml-2 text-ink-muted">(required)</span>}
                                        </dt>
                                        <dd className="text-sm text-ink-muted">{column.description}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * Row problems from the server's "rows.{row}.{column}" errors, by row and
 * then in file column order.
 */
function rowProblems(errors: Partial<Record<string, string>>): RowProblem[] {
    const problems: RowProblem[] = [];

    for (const [key, message] of Object.entries(errors)) {
        const [, row, column] = ROW_PROBLEM_KEY.exec(key) ?? [];
        if (row !== undefined && column !== undefined && message !== undefined) {
            problems.push({ row: Number(row), column, message });
        }
    }

    return problems.sort((first, second) => first.row - second.row || COLUMN_ORDER.indexOf(first.column) - COLUMN_ORDER.indexOf(second.column));
}
