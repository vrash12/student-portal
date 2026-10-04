import { StatusBadge } from '@/components/ui/status-badge';
import { formatGrade } from '@/lib/format';
import type { GradingThresholds, OverallStanding, StatusValue, SubjectResult } from '@/types/grading';

/**
 * A server-decided academic standing as a badge, or a dash when there is
 * none. Always text plus icon, never color alone.
 */
export function StandingBadge({ standing }: { standing: StatusValue | null }) {
    if (standing === null) {
        return (
            <span className="text-ink-muted">
                <span aria-hidden="true">—</span>
                <span className="sr-only">No standing yet</span>
            </span>
        );
    }

    return <StatusBadge tone={standing.tone}>{standing.label}</StatusBadge>;
}

/**
 * Standing with the grade status underneath, for tables where both are
 * needed but space is limited (the gradebook, the candidate profile). A
 * provisional grade also says how much of the weight it is based on, since
 * the grade status may be showing missing scores instead.
 */
export function StandingCell({ result }: { result: SubjectResult }) {
    return (
        <div className="flex flex-col items-start gap-0.5">
            <StandingBadge standing={result.standing} />
            <span className="text-xs text-ink-muted">
                Grade: {result.status.label}
                {result.missingScores > 0 && ` (${result.missingScores})`}
                {result.isProvisional && ` · ${result.assessedWeight}% of weight assessed`}
            </span>
        </div>
    );
}

/**
 * The grade status alone, used where no standing can be shown because the
 * academic period has no passing and warning grades.
 */
export function GradeStatusBadge({ result }: { result: SubjectResult }) {
    return (
        <StatusBadge tone={result.status.tone}>
            {result.status.label}
            {result.missingScores > 0 && ` (${result.missingScores})`}
        </StatusBadge>
    );
}

/** "passing grade 75.00, warning grade 80.00", for use inside a sentence. */
export function ThresholdSummary({ thresholds }: { thresholds: GradingThresholds }) {
    return (
        <>
            passing grade <span className="tabular-nums">{formatGrade(thresholds.passingGrade)}</span>, warning grade{' '}
            <span className="tabular-nums">{formatGrade(thresholds.warningGrade)}</span>
        </>
    );
}

/**
 * How standings follow from the passing and warning grades. Shared by the
 * thresholds form preview and the grade breakdown so the wording is the
 * same everywhere. Thresholds are in whole hundredths (7500 = 75.00).
 */
export function StandingRanges({ passingHundredths, warningHundredths }: { passingHundredths: number; warningHundredths: number }) {
    return (
        <dl className="grid gap-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt>
                <StatusBadge tone="success">Passing</StatusBadge>
            </dt>
            <dd className="text-ink">
                <span className="tabular-nums">{formatHundredths(warningHundredths)}</span> and above, no missing scores
            </dd>
            <dt>
                <StatusBadge tone="warning">Needs Improvement</StatusBadge>
            </dt>
            <dd className="text-ink">
                {passingHundredths === warningHundredths ? (
                    'None: warning grade equals passing grade'
                ) : (
                    <span className="tabular-nums">
                        {formatHundredths(passingHundredths)} to {formatHundredths(warningHundredths - 1)}
                    </span>
                )}
            </dd>
            <dt>
                <StatusBadge tone="danger">Failing</StatusBadge>
            </dt>
            <dd className="text-ink">
                Below <span className="tabular-nums">{formatHundredths(passingHundredths)}</span>
            </dd>
            <dt>
                <StatusBadge tone="neutral">Incomplete</StatusBadge>
            </dt>
            <dd className="text-ink">Missing scores; grade so far not below the warning grade</dd>
        </dl>
    );
}

/**
 * The overall standing and what it rests on, e.g. "Most serious subject
 * standing · based on 2 of 4 subjects".
 */
export function OverallStandingValue({ overall }: { overall: OverallStanding }) {
    return (
        <div className="flex flex-col items-start gap-0.5">
            <StandingBadge standing={overall.standing} />
            {overall.standing !== null && (
                <span className="text-xs font-normal text-ink-muted">
                    Most serious subject standing · based on {overall.basedOnSubjects} of {overall.totalSubjects}{' '}
                    {overall.totalSubjects === 1 ? 'subject' : 'subjects'}
                    {overall.isProvisional && ' · includes grades in progress'}
                </span>
            )}
        </div>
    );
}

function formatHundredths(hundredths: number): string {
    return (hundredths / 100).toFixed(2);
}
