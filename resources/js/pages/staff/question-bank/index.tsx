import { Head } from '@inertiajs/react';
import { ListCharts, type ListChart } from '@/components/charts/list-charts';
import { BookOpenCheck, ClipboardList, FileUp, Plus, SearchX } from 'lucide-react';
import { pointsLabel } from '@/components/question-bank/question-form-data';
import { LockedIndicator, QuestionStatusBadge } from '@/components/question-bank/question-status';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, SearchField } from '@/components/ui/filter-bar';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { ExaminationSectionTabs } from '@/components/examinations/examination-section-tabs';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import { examinationRoutes } from '@/lib/examination-routes';
import { routes } from '@/lib/routes';
import { useQueryFilters } from '@/lib/use-query-filters';
import type { Paginated } from '@/types';
import type { QuestionBankSubject, QuestionSummary, QuestionTypeOption } from '@/types/question-bank';

interface QuestionBankFilters {
    search: string;
    subject: string;
    topic: string;
    type: string;
    status: string;
    [key: string]: string;
}

interface QuestionBankIndexProps {
    /** Charts computed by the server from the filtered list. */
    charts: ListChart[];
    questions: Paginated<QuestionSummary>;
    filters: QuestionBankFilters;
    /** The subjects the user teaches; the list never includes other subjects. */
    subjects: QuestionBankSubject[];
    /** Topics of the selected subject that have questions. */
    topics: Array<{ id: number; name: string }>;
    types: QuestionTypeOption[];
}

export default function QuestionBankIndex({ questions, filters, subjects, topics, types, charts }: QuestionBankIndexProps) {
    const { values, update, updateMany, reset, isFiltered } = useQueryFilters(routes.questionBank.index(), filters);
    const selectedSubject = subjects.find((subject) => String(subject.id) === filters.subject) ?? null;
    const onlySubjectFiltered = selectedSubject !== null && ['search', 'topic', 'type', 'status'].every((key) => values[key] === '');

    const addQuestion = (
        <ButtonLink
            href={routes.questionBank.create(values.subject === '' ? {} : { subject: values.subject })}
            variant="primary"
            icon={<Plus className="size-4" aria-hidden="true" />}
        >
            Add Question
        </ButtonLink>
    );

    return (
        <>
            <Head title="Question Bank" />

            <PageHeader
                title="Question Bank"
                breadcrumbs={[{ label: 'Examinations', href: examinationRoutes.index() }, { label: 'Question Bank' }]}
                description="Reusable questions for the subjects you teach. Correct answers and explanations are visible to staff only."
                actions={
                    subjects.length > 0 ? (
                        <>
                            <ButtonLink
                                href={routes.questionBank.import.create(values.subject === '' ? {} : { subject: values.subject })}
                                icon={<FileUp className="size-4" aria-hidden="true" />}
                            >
                                Import Questions
                            </ButtonLink>
                            {(questions.data.length > 0 || (isFiltered && !onlySubjectFiltered)) && addQuestion}
                        </>
                    ) : undefined
                }
            />

            <ExaminationSectionTabs current="questions" />

            <ListCharts charts={charts} />

            {subjects.length === 0 ? (
                <div className="rounded-lg border border-line-box bg-surface">
                    <EmptyState
                        icon={ClipboardList}
                        title="You do not teach any subjects yet"
                        description="Questions belong to a subject. Once an academic administrator assigns you to teach a subject of a class, you can add questions for it here."
                    />
                </div>
            ) : (
                <div className="rounded-lg border border-line-box bg-surface">
                    <FilterBar onReset={reset} canReset={isFiltered}>
                        <SearchField
                            placeholder="Search questions by text…"
                            value={values.search}
                            onChange={(value) => update('search', value, { debounce: true })}
                        />
                        <FormField label="Subject" className="sm:w-48">
                            <SelectInput
                                value={values.subject}
                                // Topics belong to a subject, so a subject change clears the topic.
                                onChange={(event) => updateMany({ subject: event.target.value, topic: '' })}
                            >
                                <option value="">All subjects</option>
                                {subjects.map((subject) => (
                                    <option key={subject.id} value={String(subject.id)}>
                                        {subject.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Topic" className="sm:w-48">
                            <SelectInput value={values.topic} onChange={(event) => update('topic', event.target.value)} disabled={values.subject === ''}>
                                <option value="">{values.subject === '' ? 'Select a subject first' : 'All topics'}</option>
                                {topics.map((topic) => (
                                    <option key={topic.id} value={String(topic.id)}>
                                        {topic.name}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Type" className="sm:w-44">
                            <SelectInput value={values.type} onChange={(event) => update('type', event.target.value)}>
                                <option value="">All types</option>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                        <FormField label="Status" className="sm:w-40">
                            <SelectInput value={values.status} onChange={(event) => update('status', event.target.value)}>
                                <option value="">All statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </SelectInput>
                        </FormField>
                    </FilterBar>

                    {questions.data.length === 0 ? (
                        !isFiltered ? (
                            <EmptyState
                                icon={BookOpenCheck}
                                title="No questions yet"
                                description="Add questions for the subjects you teach. They can then be reused in quizzes and examinations."
                                action={addQuestion}
                            />
                        ) : onlySubjectFiltered ? (
                            <EmptyState
                                icon={BookOpenCheck}
                                title={`No questions for ${selectedSubject.name} yet`}
                                description="Add the first question for this subject."
                                action={addQuestion}
                            />
                        ) : (
                            <EmptyState
                                icon={SearchX}
                                title="No questions match these filters"
                                description="Try a different search term, or reset the filters."
                                action={
                                    <Button variant="secondary" onClick={reset}>
                                        Reset Filters
                                    </Button>
                                }
                            />
                        )
                    ) : (
                        <ul aria-label="Questions" className="divide-y divide-line">
                            {questions.data.map((question) => (
                                <QuestionRow key={question.id} question={question} />
                            ))}
                        </ul>
                    )}

                    <Pagination page={questions} noun={{ one: 'question', other: 'questions' }} />
                </div>
            )}
        </>
    );
}

function QuestionRow({ question }: { question: QuestionSummary }) {
    const excerptId = `question-${question.id}-excerpt`;

    return (
        <li className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-5">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                    <span className="font-semibold text-ink">{question.type.label}</span>
                    <span className="text-ink-muted tabular-nums">{pointsLabel(question.points)}</span>
                    <QuestionStatusBadge isActive={question.isActive} />
                    {question.isLocked && <LockedIndicator />}
                </div>
                <p id={excerptId} className="mt-2 break-words text-ink">
                    {question.excerpt}
                </p>
                <p className="mt-1 text-sm text-ink-muted">
                    {question.subject.name}
                    <span aria-hidden="true"> · </span>
                    <span className="sr-only">, </span>
                    {question.topic === null ? 'No topic' : question.topic.name}
                </p>
            </div>
            <div className="flex shrink-0 gap-2">
                <ButtonLink href={routes.questionBank.show(question.id)} size="sm" aria-describedby={excerptId}>
                    Preview
                </ButtonLink>
                <ButtonLink href={routes.questionBank.edit(question.id)} size="sm" aria-describedby={excerptId}>
                    Edit
                </ButtonLink>
            </div>
        </li>
    );
}
