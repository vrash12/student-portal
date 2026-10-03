import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ClientPagination, useClientPagination } from '@/components/ui/client-pagination';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { examinationRoutes } from '@/lib/examination-routes';

interface Review {
    exam: { id: number; title: string; subject: string; className: string };
    categories: Array<{ id: number; name: string; weight: string }>;
    rows: Array<{ candidateId: number; candidateNumber: string; name: string; attemptNumber: number | null; score: string | null; status: string }>;
    maximum: string;
    rule: 'highest' | 'latest';
    readyCount: number;
    missingCount: number;
    excludedCount: number;
    issues: string[];
    posted: { id: number; title: string } | null;
    reviewToken: string;
}

/** Tones for the row statuses sent by ExaminationGradebookService. */
const rowStatusTones: Record<string, StatusTone> = {
    Ready: 'success',
    'Awaiting grading': 'warning',
    'No submitted result': 'neutral',
};

/** Errors shown next to their own field; anything else goes in the Alert. */
const FIELD_ERROR_KEYS = new Set(['title', 'assessment_category_id', 'attempt_rule']);

export default function PostGrades({ review }: { review: Review }) {
    // A new server preview resets confirmation and its stale-review token together.
    return <PostingForm key={review.reviewToken} review={review} />;
}

function PostingForm({ review }: { review: Review }) {
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ title: review.exam.title.slice(0, 150), assessment_category_id: '', attempt_rule: review.rule, review_token: review.reviewToken, confirmed: true });
    const pagination = useClientPagination(review.rows);
    const formErrors = Object.entries(form.errors)
        .filter(([key, message]) => !FIELD_ERROR_KEYS.has(key) && message !== undefined)
        .map(([, message]) => message);
    const categoryName = review.categories.find((category) => String(category.id) === form.data.assessment_category_id)?.name ?? 'the selected component';

    return (
        <>
            <Head title="Post Examination Grades" />
            <PageHeader
                title="Post Examination Grades"
                description={`${review.exam.title} · ${review.exam.subject} · ${review.exam.className}`}
                breadcrumbs={[
                    { label: 'Examinations', href: examinationRoutes.index() },
                    { label: review.exam.title, href: examinationRoutes.show(review.exam.id) },
                    { label: 'Post Grades' },
                ]}
            />
            {review.posted ? (
                <Alert tone="success" title="Already posted">
                    <p>This examination contributes once through {review.posted.title}. Later changes require an audited gradebook correction.</p>
                    <ButtonLink href={`/assessments/${review.posted.id}`} variant="secondary" className="mt-3">
                        Open Posted Assessment
                    </ButtonLink>
                </Alert>
            ) : (
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        setConfirming(true);
                    }}
                    className="flex flex-col gap-6"
                >
                    <Alert tone="info" title="Review before posting">
                        <p>
                            This creates and finalizes one assessment in the selected component. Its raw scores count toward academic grades immediately and become
                            visible to candidates. Existing assessments are not overwritten.
                        </p>
                        <p className="mt-2">
                            Posting is a recorded snapshot. Later exam regrading does not automatically change the gradebook; use its Correct Score action with a
                            reason.
                        </p>
                    </Alert>
                    {review.issues.length > 0 && (
                        <Alert tone="warning" title="Before you can post">
                            <ul className="list-disc pl-5">
                                {review.issues.map((issue) => (
                                    <li key={issue}>{issue}</li>
                                ))}
                            </ul>
                        </Alert>
                    )}
                    {formErrors.length > 0 && (
                        <Alert tone="danger" title="Unable to post">
                            <ul className="space-y-1">
                                {formErrors.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </Alert>
                    )}
                    <Panel title="Gradebook Assessment">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Assessment Title" required error={form.errors.title}>
                                <TextInput value={form.data.title} maxLength={150} onChange={(event) => form.setData('title', event.target.value)} />
                            </FormField>
                            <FormField label="Grading Component" required error={form.errors.assessment_category_id}>
                                <SelectInput value={form.data.assessment_category_id} onChange={(event) => form.setData('assessment_category_id', event.target.value)}>
                                    <option value="">Select a component</option>
                                    {review.categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name} ({category.weight}%)
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField
                                label="Attempt to Count"
                                hint="Only submitted attempts are considered. All submissions must be fully graded."
                                error={form.errors.attempt_rule}
                            >
                                <SelectInput
                                    value={review.rule}
                                    onChange={(event) => router.get(examinationRoutes.gradebook(review.exam.id), { rule: event.target.value }, { preserveScroll: true })}
                                >
                                    <option value="highest">Highest score (latest on a tie)</option>
                                    <option value="latest">Latest submitted attempt</option>
                                </SelectInput>
                            </FormField>
                            <div className="text-sm text-ink-muted">
                                <p>
                                    Maximum score: <strong className="text-ink tabular-nums">{review.maximum}</strong>
                                </p>
                                <p className="mt-2">Category weights remain those configured for this subject.</p>
                            </div>
                        </div>
                    </Panel>
                    <Panel
                        title="Scores to Post"
                        description={`${review.readyCount} scores · ${review.missingCount} missing · ${review.excludedCount} former or withdrawn candidates excluded. Missing scores stay blank, never zero.`}
                        bodyClassName="p-0"
                    >
                        {review.rows.length === 0 ? (
                            <p className="p-5 text-sm text-ink-muted">No eligible candidates in this class.</p>
                        ) : (
                            <Table caption="Review examination scores before posting" className="min-w-[32rem]">
                                <TableHead>
                                    <Th>Candidate</Th>
                                    <Th align="right">Attempt</Th>
                                    <Th align="right">Score</Th>
                                    <Th>Status</Th>
                                </TableHead>
                                <TableBody>
                                    {pagination.rows.map((row) => (
                                        <Tr key={row.candidateId}>
                                            <Td>
                                                <p className="font-medium">{row.name}</p>
                                                <p className="text-xs text-ink-muted">{row.candidateNumber}</p>
                                            </Td>
                                            <Td numeric align="right">
                                                {row.attemptNumber ?? '—'}
                                            </Td>
                                            <Td numeric align="right">
                                                {row.score === null ? '—' : `${row.score} / ${review.maximum}`}
                                            </Td>
                                            <Td>
                                                <StatusBadge tone={rowStatusTones[row.status] ?? 'neutral'}>{row.status}</StatusBadge>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <ClientPagination pagination={pagination} noun={{ one: 'candidate', other: 'candidates' }} label="Score preview pages" />
                    </Panel>
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={review.issues.length > 0 || !form.data.assessment_category_id || !form.data.title.trim()}
                            loading={form.processing}
                        >
                            Review & Confirm Posting
                        </Button>
                    </div>
                </form>
            )}
            <ConfirmDialog
                open={confirming}
                title="Post these scores to the gradebook?"
                description={`${review.readyCount} scores will be finalized under ${categoryName}. ${review.missingCount} candidates will have missing scores. Academic grades will update immediately.`}
                confirmLabel="Post & Finalize Grades"
                tone="primary"
                processing={form.processing}
                onCancel={() => setConfirming(false)}
                onConfirm={() => form.post(examinationRoutes.gradebook(review.exam.id), { preserveScroll: true, onFinish: () => setConfirming(false) })}
            />
        </>
    );
}
