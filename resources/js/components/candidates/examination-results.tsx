import { Panel } from '@/components/ui/panel';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatPercent, useDateFormatter } from '@/lib/format';
import type { Paginated } from '@/types';
import type { CandidateExaminationResult } from '@/types/candidates';

export function CandidateExaminationResults({ results, portal = false }: { results: Paginated<CandidateExaminationResult>; portal?: boolean }) {
    const dates = useDateFormatter();

    return <Panel title="Quiz & Examination Results" description={portal ? 'Your attempts. Scores appear when released; essays may await instructor review.' : 'Attempts in the subjects you are authorized to view. Scores awaiting review remain pending.'} bodyClassName="p-0">
        {results.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No quiz or examination attempts yet.</p> : <>
            <Table caption="Quiz and examination results" className="min-w-[38rem]">
                <TableHead><Th>Assessment</Th><Th>Submitted</Th><Th>Status</Th><Th align="right">Score</Th><Th>Result</Th></TableHead>
                <TableBody>{results.data.map((result) => <Tr key={result.id}>
                    <Td><p className="font-medium text-ink">{result.title}</p><p className="text-xs text-ink-muted">{result.kind} · Attempt {result.attemptNumber}</p><p className="text-xs text-ink-muted">{result.subject} · {result.className}</p></Td>
                    <Td className="text-ink-muted">{result.submittedAt ? dates.dateTime(result.submittedAt) : 'Not submitted'}</Td>
                    <Td><StatusBadge tone="neutral">{result.status}</StatusBadge></Td>
                    <Td align="right" numeric>{result.score === null ? '—' : <><p>{result.score} / {result.maxScore}</p><p className="text-xs text-ink-muted">{result.percentage === null ? '—' : formatPercent(result.percentage)}</p></>}</Td>
                    <Td>{result.passed === null ? <span className="text-sm text-ink-muted">{result.resultLabel}</span> : <StatusBadge tone={result.passed ? 'success' : 'danger'}>{result.passed ? 'Passed' : 'Failed'}</StatusBadge>}</Td>
                </Tr>)}</TableBody>
            </Table><Pagination page={results} noun={{ one: 'attempt', other: 'attempts' }} />
        </>}
    </Panel>;
}
