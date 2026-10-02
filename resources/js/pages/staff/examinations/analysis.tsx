import { Head, Link } from '@inertiajs/react';
import { ChartColumn, CircleCheck, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { ChartFigure } from '@/components/charts/chart-figure';
import { ColumnChart } from '@/components/charts/column-chart';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { cn } from '@/lib/cn';
import { examinationRoutes } from '@/lib/examination-routes';
import { formatPercent, useDateFormatter } from '@/lib/format';
import type {
    ItemAnalysis,
    ItemAnalysisExamination,
    ItemAnalysisFlag,
    ItemAnalysisFlagCode,
    ItemAnalysisQuestion,
    ItemAnalysisScope,
    ItemAnalysisSort,
} from '@/types/item-analysis';

interface ItemAnalysisPageProps {
    examination: ItemAnalysisExamination;
    analysis: ItemAnalysis;
    generatedAt: string;
}

const scopeOptions: { value: ItemAnalysisScope; label: string }[] = [
    { value: 'latest', label: 'Latest attempt per candidate' },
    { value: 'all', label: 'All submitted attempts' },
];

const sortOptions: { value: ItemAnalysisSort; label: string }[] = [
    { value: 'missed', label: 'Most missed first' },
    { value: 'order', label: 'Examination order' },
    { value: 'discrimination', label: 'Lowest discrimination first' },
];

const flagTones: Record<ItemAnalysisFlagCode, StatusTone> = {
    mostly_missed: 'danger',
    very_easy: 'info',
    negative_discrimination: 'warning',
    distractor_preferred: 'warning',
};

const EMPTY_VALUE = '—';

/**
 * Item analysis of an examination's submitted attempts (staff only). Every
 * figure comes from the server; the page formats and arranges it. Aggregate
 * only: no candidate names or individual answers are ever shown.
 */
export default function ExaminationItemAnalysis({ examination, analysis, generatedAt }: ItemAnalysisPageProps) {
    const { dateTime } = useDateFormatter();
    const { summary, discrimination, questions } = analysis;
    const hasSubmissions = summary.submittedAttempts > 0;
    const query = (overrides: Partial<Record<'scope' | 'sort', string>>) => examinationRoutes.analysis(examination.id, { scope: analysis.scope, sort: analysis.sort, ...overrides });

    return (
        <>
            <Head title={`Item analysis · ${examination.title}`} />
            <PageHeader
                title="Item analysis"
                description={`${examination.title} · ${examination.kind} · ${examination.subject} · ${examination.classBatch}`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                    { label: examination.title, href: examinationRoutes.show(examination.id) },
                    { label: 'Item analysis' },
                ]}
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <ButtonLink href={examinationRoutes.show(examination.id)}>Back to examination</ButtonLink>
                        {hasSubmissions && (
                            <Button variant="secondary" icon={<Printer className="size-4" aria-hidden="true" />} onClick={() => window.print()}>
                                Print
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="mb-6 flex flex-col gap-4 rounded-xl border border-line-box bg-surface p-4 lg:flex-row lg:items-end lg:justify-between print:hidden">
                <SegmentedLinks label="Attempts counted" options={scopeOptions} current={analysis.scope} hrefFor={(value) => query({ scope: value })} />
                <SegmentedLinks label="Order questions by" options={sortOptions} current={analysis.sort} hrefFor={(value) => query({ sort: value })} />
            </div>

            <p className="mb-4 text-sm text-ink-muted">
                Generated {dateTime(generatedAt)} from submitted attempts only (including automatically submitted ones), using the questions and answer keys each
                candidate received. {analysis.scope === 'latest' ? 'Only the latest submitted attempt of each candidate is counted.' : 'Every submitted attempt is counted.'}{' '}
                Figures are totals; individual answers are not shown.
            </p>

            {!hasSubmissions ? (
                <Panel bodyClassName="p-0">
                    <EmptyState
                        icon={ChartColumn}
                        title="No submitted attempts yet"
                        description="Item analysis appears after candidates submit this examination. Attempts still in progress, and expired attempts that were not submitted, are not counted."
                    />
                </Panel>
            ) : (
                <div className="space-y-6">
                    <SummaryPanel analysis={analysis} />

                    {!discrimination.available && discrimination.reason && (
                        <Alert tone="info" title="Discrimination index not shown">
                            {discrimination.reason}
                        </Alert>
                    )}
                    {analysis.undeliveredQuestions > 0 && (
                        <Alert tone="info">
                            {analysis.undeliveredQuestions === 1 ? '1 question of this examination was' : `${analysis.undeliveredQuestions} questions of this examination were`} not
                            delivered in any counted attempt and {analysis.undeliveredQuestions === 1 ? 'is' : 'are'} not listed.
                        </Alert>
                    )}

                    <OverviewTable questions={questions} sortLabel={sortOptions.find((option) => option.value === analysis.sort)?.label ?? ''} />

                    <section aria-labelledby="question-details-heading" className="space-y-4">
                        <h2 id="question-details-heading" className="text-lg font-bold text-primary-900">
                            Question details
                        </h2>
                        {questions.map((question) => (
                            <QuestionDetail key={question.id} question={question} />
                        ))}
                    </section>
                </div>
            )}
        </>
    );
}

function SegmentedLinks<T extends string>({ label, options, current, hrefFor }: { label: string; options: { value: T; label: string }[]; current: T; hrefFor: (value: T) => string }) {
    return (
        <nav aria-label={label} className="min-w-0">
            <p className="mb-2 text-sm font-medium text-ink">{label}</p>
            <ul className="flex flex-wrap gap-2">
                {options.map((option) => {
                    const active = option.value === current;

                    return (
                        <li key={option.value}>
                            <Link
                                href={hrefFor(option.value)}
                                preserveScroll
                                aria-current={active ? 'true' : undefined}
                                className={cn(
                                    'inline-flex min-h-10 items-center rounded-md px-3 text-sm font-medium pointer-coarse:min-h-11',
                                    active ? 'bg-primary-700 text-white' : 'border border-line bg-surface text-ink hover:bg-primary-50',
                                )}
                            >
                                {option.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function SummaryPanel({ analysis }: { analysis: ItemAnalysis }) {
    const { summary } = analysis;

    return (
        <Panel title="Summary" description="Percentages are final examination scores. Attempts awaiting essay grading have no final score yet and are excluded from these figures.">
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Submitted attempts" value={String(summary.submittedAttempts)} />
                <Stat label="Candidates" value={String(summary.candidates)} />
                <Stat label="Awaiting essay grading" value={String(summary.awaitingGrading)} />
                <Stat label="With a final score" value={String(summary.scoredAttempts)} />
                <Stat label="Mean" value={formatPercent(summary.mean)} />
                <Stat label="Median" value={formatPercent(summary.median)} />
                <Stat label="Highest" value={formatPercent(summary.highest)} />
                <Stat label="Lowest" value={formatPercent(summary.lowest)} />
                {summary.passingScore !== null && (
                    <Stat
                        label="Pass rate"
                        value={formatPercent(summary.passRate)}
                        description={`${summary.passed ?? 0} of ${summary.scoredAttempts} scored attempts reached the passing score of ${formatPercent(summary.passingScore)}`}
                    />
                )}
            </dl>
            {summary.scoreDistribution.length > 0 && (
                <ChartFigure
                    title="Score Distribution"
                    description={`Final percentages of the ${summary.scoredAttempts} scored ${summary.scoredAttempts === 1 ? 'attempt' : 'attempts'} by range.`}
                    className="mt-6 border-t border-line pt-5"
                >
                    <ColumnChart columns={summary.scoreDistribution} noun={{ one: 'attempt', other: 'attempts' }} />
                </ChartFigure>
            )}
        </Panel>
    );
}

function Stat({ label, value, description }: { label: string; value: string; description?: string }) {
    return (
        <div className="rounded-lg border border-line-box bg-surface px-4 py-3">
            <dt className="text-sm font-medium text-ink-muted">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums text-ink">{value}</dd>
            {description && <dd className="mt-1 text-sm text-ink-subtle">{description}</dd>}
        </div>
    );
}

function OverviewTable({ questions, sortLabel }: { questions: ItemAnalysisQuestion[]; sortLabel: string }) {
    const pagination = useClientPagination(questions);

    return (
        <Panel title="Questions" description={`Ordered: ${sortLabel.toLowerCase()}. Percent correct counts unanswered deliveries as not correct; for essays it is the average score of graded responses.`} bodyClassName="p-0">
            <Table caption={`Item analysis by question, ${sortLabel.toLowerCase()}`} className="min-w-[56rem]">
                <TableHead>
                    <Th>Question</Th>
                    <Th>Type</Th>
                    <Th align="right">Delivered</Th>
                    <Th align="right">Answered</Th>
                    <Th align="right">Unanswered</Th>
                    <Th align="right">Correct / average</Th>
                    <Th align="right">Discrimination</Th>
                    <Th>Review notes</Th>
                </TableHead>
                <tbody className="divide-y divide-line print:hidden">
                    {pagination.rows.map((question) => (
                        <OverviewRow key={question.id} question={question} />
                    ))}
                </tbody>
                {/* The printed analysis always lists every question, whatever page is on screen. */}
                <tbody className="hidden divide-y divide-line print:table-row-group">
                    {questions.map((question) => (
                        <OverviewRow key={question.id} question={question} />
                    ))}
                </tbody>
            </Table>
            <div className="print:hidden">
                <ClientPagination pagination={pagination} noun={{ one: 'question', other: 'questions' }} label="Question pages" />
            </div>
        </Panel>
    );
}

function OverviewRow({ question }: { question: ItemAnalysisQuestion }) {
    return (
        <Tr>
            <Td>
                <a href={`#question-${question.id}`} className="font-medium text-primary-700 underline-offset-2 hover:underline">
                    {questionName(question)}
                </a>
            </Td>
            <Td>{question.type.label}</Td>
            <Td align="right" numeric>
                {question.delivered}
            </Td>
            <Td align="right" numeric>
                {question.answered}
            </Td>
            <Td align="right" numeric>
                {question.unanswered}
            </Td>
            <Td align="right" numeric>
                {formatPercent(question.difficulty)}
            </Td>
            <Td align="right" numeric>
                {formatDiscrimination(question.discrimination)}
            </Td>
            <Td>
                <Flags flags={question.flags} />
            </Td>
        </Tr>
    );
}

function QuestionDetail({ question }: { question: ItemAnalysisQuestion }) {
    const isEssay = question.essay !== null;

    return (
        <article id={`question-${question.id}`} className="scroll-mt-6 rounded-xl border border-line-box bg-surface print:break-inside-avoid">
            <header className="flex flex-col gap-2 rounded-t-xl border-b border-line bg-primary-50/70 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h3 className="text-base font-bold text-primary-900">{questionName(question)}</h3>
                    <p className="text-sm text-ink-muted">
                        {question.type.label} · {question.points} {question.points === 1 ? 'point' : 'points'}
                    </p>
                </div>
                <Flags flags={question.flags} />
            </header>
            <div className="space-y-4 p-5">
                <p className="whitespace-pre-wrap font-medium text-ink">{question.prompt}</p>
                <QuestionMediaList className="space-y-3" media={question.media} urlFor={(media) => `/question-media/${media.id}`} />

                <dl className="grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <Figure label="Delivered" value={String(question.delivered)} />
                    <Figure label="Answered" value={String(question.answered)} />
                    <Figure label="Unanswered" value={String(question.unanswered)} />
                    {isEssay && question.essay ? (
                        <>
                            <Figure label="Graded" value={`${question.essay.graded} of ${question.delivered}`} />
                            <Figure
                                label="Average score"
                                value={question.essay.averageScore === null ? EMPTY_VALUE : `${question.essay.averageScore.toFixed(2)} / ${question.essay.maxPoints.toFixed(2)}`}
                            />
                            <Figure label="Average percent" value={formatPercent(question.essay.averagePercent)} />
                        </>
                    ) : (
                        <>
                            <Figure label="Correct" value={String(question.correct ?? 0)} />
                            <Figure label="Percent correct" value={formatPercent(question.percentCorrect)} />
                        </>
                    )}
                    <Figure label="Discrimination" value={formatDiscrimination(question.discrimination)} />
                </dl>

                {isEssay && question.essay && question.essay.ungraded > 0 && (
                    <p className="text-sm text-ink-muted">
                        {question.essay.ungraded} {question.essay.ungraded === 1 ? 'response is' : 'responses are'} not graded yet and {question.essay.ungraded === 1 ? 'is' : 'are'} not
                        included in the average.
                    </p>
                )}

                {!isEssay && <ChoiceDistribution question={question} />}
            </div>
        </article>
    );
}

function ChoiceDistribution({ question }: { question: ItemAnalysisQuestion }) {
    return (
        <div className="rounded-lg border border-line-box">
            <Table caption={`Answer choices chosen for ${questionName(question)}, out of ${question.delivered} deliveries`} className="min-w-[36rem]">
                <TableHead>
                    <Th>Choice</Th>
                    <Th>Answer key</Th>
                    <Th align="right">Chosen</Th>
                    <Th align="right">Share</Th>
                    <Th className="w-48">
                        <span className="sr-only">Share bar</span>
                    </Th>
                </TableHead>
                <TableBody>
                    {question.choices.map((choice) => (
                        <Tr key={choice.id}>
                            <Td className="align-top">
                                <span className="font-semibold">{choice.label}.</span> <span className="whitespace-pre-wrap">{choice.text}</span>
                            </Td>
                            <Td className="align-top">
                                {choice.isCorrect ? (
                                    <span className="inline-flex items-center gap-1 font-medium text-success-fg">
                                        <CircleCheck className="size-4 shrink-0" aria-hidden="true" />
                                        Correct answer
                                    </span>
                                ) : (
                                    <span className="text-ink-muted">Distractor</span>
                                )}
                            </Td>
                            <Td align="right" numeric className="align-top">
                                {choice.count}
                            </Td>
                            <Td align="right" numeric className="align-top">
                                {formatPercent(choice.percent)}
                            </Td>
                            <Td className="align-top">
                                <ShareBar percent={choice.percent} correct={choice.isCorrect} />
                            </Td>
                        </Tr>
                    ))}
                    <Tr>
                        <Td className="text-ink-muted">No answer</Td>
                        <Td>
                            <span className="text-ink-muted">Not correct</span>
                        </Td>
                        <Td align="right" numeric>
                            {question.unanswered}
                        </Td>
                        <Td align="right" numeric>
                            {formatPercent(question.unansweredPercent)}
                        </Td>
                        <Td>
                            <span className="sr-only">No bar</span>
                        </Td>
                    </Tr>
                </TableBody>
            </Table>
        </div>
    );
}

/** Decorative bar; the percentage is always given as text in the previous column. */
function ShareBar({ percent, correct }: { percent: number | null; correct: boolean }) {
    return (
        <div className="h-2.5 w-full min-w-24 rounded-full bg-neutral-bg" aria-hidden="true">
            <div className={cn('h-2.5 rounded-full', correct ? 'bg-primary-600' : 'bg-ink-subtle')} style={{ width: `${Math.min(100, Math.max(0, percent ?? 0))}%` }} />
        </div>
    );
}

function Figure({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-ink-muted">{label}</dt>
            <dd className="font-semibold tabular-nums text-ink">{value}</dd>
        </div>
    );
}

function Flags({ flags }: { flags: ItemAnalysisFlag[] }) {
    if (flags.length === 0) {
        return <span className="text-sm text-ink-muted">None</span>;
    }

    return (
        <ul className="flex flex-wrap gap-1.5">
            {flags.map((flag) => (
                <li key={flag.code}>
                    <StatusBadge tone={flagTones[flag.code]}>{flag.label}</StatusBadge>
                </li>
            ))}
        </ul>
    );
}

function questionName(question: ItemAnalysisQuestion): string {
    return question.position === null ? 'Removed question' : `Question ${question.position}`;
}

/** Discrimination index from −1 to 1, two decimals, with a true minus sign. */
function formatDiscrimination(value: number | null): string {
    if (value === null) {
        return EMPTY_VALUE;
    }

    return value < 0 ? `−${Math.abs(value).toFixed(2)}` : value.toFixed(2);
}
