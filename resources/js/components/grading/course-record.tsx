import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/cn';
import { formatGrade } from '@/lib/format';
import type { CourseRecord, TrainingPhaseSummary } from '@/types/grading';

/** "Phase 1", or "Not in a phase" for subjects not placed in one. */
export function phaseName(phase: TrainingPhaseSummary | null): string {
    return phase?.name ?? 'Not in a phase';
}

/** "1 unit", "3 units", "1.5 units". */
export function unitsLabel(units: string): string {
    return units === '1' ? '1 unit' : `${units} units`;
}

/** Whether any subject is placed in a phase; phase averages are shown only then. */
export function hasPhases(course: CourseRecord): boolean {
    return course.phases.some((group) => group.phase !== null);
}

/**
 * Each training phase's average and the CGPA (Cumulative General Point
 * Average), as calculated by the server: unit-weighted averages of the
 * subject grades, subjects without a grade left out. Every state is written
 * out (never colour alone).
 */
export function CourseRecordSummary({ course }: { course: CourseRecord }) {
    const { cgpa } = course;

    return (
        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {hasPhases(course) &&
                course.phases.map((group) => (
                    <AverageCard
                        key={group.phase?.id ?? 'none'}
                        title={phaseName(group.phase)}
                        value={group.average}
                        complete={group.complete}
                        detail={`${group.gradedSubjects} of ${group.totalSubjects} ${group.totalSubjects === 1 ? 'subject' : 'subjects'} graded · ${unitsLabel(group.units)}`}
                        subjects={group.subjects.map((subject) => subject.name).join(', ')}
                    />
                ))}
            <AverageCard
                title="CGPA"
                emphasis
                value={cgpa.grade}
                complete={cgpa.complete}
                detail={`Cumulative General Point Average · ${cgpa.gradedSubjects} of ${cgpa.totalSubjects} ${cgpa.totalSubjects === 1 ? 'subject' : 'subjects'} graded`}
            />
        </dl>
    );
}

function AverageCard({ title, value, complete, detail, subjects, emphasis = false }: { title: string; value: number | null; complete: boolean; detail: string; subjects?: string; emphasis?: boolean }) {
    return (
        <div className={cn('flex flex-col gap-2 rounded-xl border p-4', emphasis ? 'border-primary-300 bg-primary-50/60' : 'border-line-box bg-surface')}>
            <dt className="text-sm font-semibold text-ink">{title}</dt>
            <dd className="text-2xl font-bold tabular-nums text-ink">{formatGrade(value)}</dd>
            <dd>
                {value === null ? (
                    <StatusBadge tone="neutral">No grades yet</StatusBadge>
                ) : complete ? (
                    <StatusBadge tone="success">Final</StatusBadge>
                ) : (
                    <StatusBadge tone="info">In progress</StatusBadge>
                )}
            </dd>
            <dd className="text-xs text-ink-muted">{detail}</dd>
            {subjects && <dd className="text-xs text-ink-muted">{subjects}</dd>}
        </div>
    );
}
