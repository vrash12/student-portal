import { Head } from '@inertiajs/react';
import { CandidateInformationPanels } from '@/components/candidates/candidate-information';
import { CandidateExaminationResults } from '@/components/candidates/examination-results';
import { OverallStandingValue, StandingCell } from '@/components/grading/standing';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Pagination } from '@/components/ui/pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatCalendarDate, formatGrade, formatPercent } from '@/lib/format';
import type { Paginated } from '@/types';
import type { CandidateAssessmentResult, CandidateExaminationResult, CandidateInformation } from '@/types/candidates';
import type { OverallStanding, SubjectGrade } from '@/types/grading';

interface ProfileProps {
    candidate: CandidateInformation;
    academics: {
        overall: OverallStanding;
        subjects: Array<{ id: number; code: string; name: string; instructors: string[]; result: SubjectGrade }>;
    };
    assessmentHistory: Paginated<CandidateAssessmentResult>;
    examinationResults: Paginated<CandidateExaminationResult>;
}

export default function CandidateProfile({ candidate, academics, assessmentHistory, examinationResults }: ProfileProps) {
    return <>
        <Head title="My Information" />
        <PageHeader title="My Information" description="Your training and academic record. Contact the academic office to correct your personal details." />
        <div className="flex flex-col gap-6">
            <Panel title="Academic Standing" description="Current standing in your assigned class, calculated from finalized assessments.">
                <OverallStandingValue overall={academics.overall} />
                {candidate.status.value === 'withdrawn' && <p className="mt-2 text-sm text-ink-muted">Standing is not monitored for withdrawn candidates. Recorded results are retained below.</p>}
            </Panel>
            <CandidateInformationPanels candidate={candidate} />
            <Panel title="Enrolled Subjects & Current Grades" description="Subjects and instructors follow your class assignment. In-progress grades may change as more assessments are finalized." bodyClassName="p-0">
                {academics.subjects.length === 0 ? <p className="p-5 text-sm text-ink-muted">No subjects assigned yet. Contact the academic office if your class assignment is missing.</p> :
                    <Table caption="Your enrolled subjects and current grades" className="min-w-[32rem]">
                        <TableHead><Th>Subject / Instructor</Th><Th align="right">Current Grade</Th><Th>Standing</Th></TableHead>
                        <TableBody>{academics.subjects.map((subject) => <Tr key={subject.id}>
                            <Td><p className="font-medium text-ink">{subject.name} <span className="text-ink-muted">({subject.code})</span></p><p className="mt-1 text-sm text-ink-muted">{subject.instructors.join(', ') || 'No instructor assigned'}</p></Td>
                            <Td align="right" numeric className="font-semibold">{formatGrade(subject.result.grade)}</Td>
                            <Td><StandingCell result={subject.result} /></Td>
                        </Tr>)}</TableBody>
                    </Table>}
            </Panel>
            <CandidateExaminationResults results={examinationResults} portal />
            <Panel title="Assessment History" description="Finalized assessments and your recorded results, including previous classes. Missing scores are not counted as zero." bodyClassName="p-0">
                {assessmentHistory.data.length === 0 ? <p className="p-5 text-sm text-ink-muted">No finalized assessments yet.</p> : <>
                    <Table caption="Your assessment history" className="min-w-[34rem]">
                        <TableHead><Th>Assessment</Th><Th>Date</Th><Th align="right">Score</Th><Th align="right">Percentage</Th></TableHead>
                        <TableBody>{assessmentHistory.data.map((result) => <Tr key={result.id}>
                            <Td><p className="font-medium text-ink">{result.title}</p><p className="text-xs text-ink-muted">{result.subject} · {result.category}</p><p className="text-xs text-ink-muted">{result.className}</p></Td>
                            <Td className="whitespace-nowrap">{formatCalendarDate(result.date)}</Td>
                            <Td align="right" numeric>{result.score === null ? <StatusBadge tone="neutral">Missing</StatusBadge> : `${result.score} / ${result.maxScore}`}</Td>
                            <Td align="right" numeric>{result.percentage === null ? '—' : formatPercent(result.percentage)}</Td>
                        </Tr>)}</TableBody>
                    </Table><Pagination page={assessmentHistory} noun={{ one: 'assessment', other: 'assessments' }} />
                </>}
            </Panel>
        </div>
    </>;
}
