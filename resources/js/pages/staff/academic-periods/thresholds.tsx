import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ROUNDING_NOTE, StandingRanges } from '@/components/grading/standing';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader, type BreadcrumbItem } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface ThresholdsFormData {
    passing_grade: string;
    warning_grade: string;
    reason: string;
}

interface GradingThresholdsProps {
    period: {
        id: number;
        name: string;
        startsOn: string;
        endsOn: string;
        isActive: boolean;
        classCount: number;
    };
    /** Current values as entered ("75", "82.5"); null when not set up yet. */
    thresholds: { passingGrade: string; warningGrade: string } | null;
    /** When not set up yet: the most recent other period's values, offered as a starting point. */
    suggestion: { fromPeriod: string; passingGrade: string; warningGrade: string } | null;
    /** The thresholds are in use by finalized grades, so a change needs a reason. */
    requiresReason: boolean;
    can: { managePeriods: boolean };
}

export default function GradingThresholds({ period, thresholds, suggestion, requiresReason, can }: GradingThresholdsProps) {
    const initial = thresholds ?? suggestion;
    const form = useForm<ThresholdsFormData>({
        passing_grade: initial?.passingGrade ?? '',
        warning_grade: initial?.warningGrade ?? '',
        reason: '',
    });

    const cancelHref = can.managePeriods ? routes.academicPeriods.index() : routes.dashboard();
    const breadcrumbs: BreadcrumbItem[] = [
        ...(can.managePeriods ? [{ label: 'Academic Periods', href: routes.academicPeriods.index() }] : []),
        { label: `Grading Thresholds: ${period.name}` },
    ];
    const classNoun = period.classCount === 1 ? terms.classBatch.singular.toLowerCase() : terms.classBatch.plural.toLowerCase();

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.academicPeriods.thresholds(period.id), {
            preserveScroll: true,
            // When the save returns to this page, the saved values become the
            // new starting point.
            onSuccess: (page) => {
                if (page.component !== 'staff/academic-periods/thresholds') {
                    return;
                }
                const saved = (page.props as unknown as GradingThresholdsProps).thresholds;
                const next = { passing_grade: saved?.passingGrade ?? '', warning_grade: saved?.warningGrade ?? '', reason: '' };
                // Set both explicitly: reset() from this closure would restore
                // the defaults of the render that submitted, not these.
                form.setDefaults(next);
                form.setData(next);
            },
        });
    };

    return (
        <>
            <Head title={`Grading Thresholds · ${period.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Grading Thresholds"
                    description={
                        <>
                            {period.name} · {formatCalendarDate(period.startsOn)} – {formatCalendarDate(period.endsOn)}{' '}
                            {period.isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>}
                        </>
                    }
                    breadcrumbs={breadcrumbs}
                />

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    {requiresReason && (
                        <Alert tone="warning" title="These thresholds are in use">
                            This period has finalized assessments. Changing the passing or warning grade immediately changes the academic standing
                            of candidates. A reason is required and is kept in the audit log.
                        </Alert>
                    )}

                    {thresholds === null && (
                        <Alert title="Not set yet">
                            {suggestion === null
                                ? 'Academic standing is not shown for this period until passing and warning grades are saved. Once set, they can be changed but not removed.'
                                : `The values below are from ${suggestion.fromPeriod}. Review them and save to use them for this period; nothing changes until you save. Once set, they can be changed but not removed.`}
                        </Alert>
                    )}

                    <FormSection
                        title="Passing and Warning Grades"
                        description={`Decide the academic standing of every candidate in the ${period.classCount} ${classNoun} of this period, in every subject. Grades are on a scale of 0 to 100.`}
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField label="Passing Grade" required error={form.errors.passing_grade} hint="Grades below this are Failing.">
                                <TextInput
                                    name="passing_grade"
                                    value={form.data.passing_grade}
                                    onChange={(event) => form.setData('passing_grade', event.target.value)}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    className="tabular-nums"
                                />
                            </FormField>
                            <FormField
                                label="Warning Grade"
                                required
                                error={form.errors.warning_grade}
                                hint="Grades from the passing grade up to, but not including, this grade are At Risk; this grade and above are Passing. Enter the passing grade again for no At Risk range."
                            >
                                <TextInput
                                    name="warning_grade"
                                    value={form.data.warning_grade}
                                    onChange={(event) => form.setData('warning_grade', event.target.value)}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    className="tabular-nums"
                                />
                            </FormField>
                        </div>

                        <StandingPreview passing={form.data.passing_grade} warning={form.data.warning_grade} />
                    </FormSection>

                    {requiresReason && (
                        <FormSection title="Reason for Change">
                            <FormField label="Reason" required error={form.errors.reason} hint="Kept in the audit log with the previous and new grades.">
                                <TextArea value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} maxLength={500} rows={3} />
                            </FormField>
                        </FormSection>
                    )}

                    <FormActions>
                        <ButtonLink href={cancelHref} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Thresholds
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}

/**
 * Labelled preview of the standing ranges for the values being entered.
 * Formatting only: the server validates the values and decides standings.
 */
function StandingPreview({ passing, warning }: { passing: string; warning: string }) {
    const passingHundredths = toHundredths(passing);
    const warningHundredths = toHundredths(warning);
    const isValid =
        passingHundredths !== null &&
        warningHundredths !== null &&
        passingHundredths > 0 &&
        passingHundredths <= warningHundredths &&
        warningHundredths <= 10000;

    return (
        // Static explanation next to the fields, not a live region: announcing
        // the whole preview on every keystroke would drown out the form.
        <div className="flex flex-col gap-2 rounded-lg border border-line-box bg-surface-muted px-4 py-3">
            <p className="text-sm font-medium text-ink">Preview</p>
            {isValid ? (
                <>
                    <StandingRanges passingHundredths={passingHundredths} warningHundredths={warningHundredths} />
                    <p className="text-sm text-ink-muted">
                        {ROUNDING_NOTE} A standing based on a grade in progress is a current standing, not a final one.
                    </p>
                </>
            ) : (
                <p className="text-sm text-ink-muted">
                    Enter a passing grade greater than 0 and a warning grade from the passing grade up to 100 to see the standing ranges.
                </p>
            )}
        </div>
    );
}

/** "75" or "75.5" as whole hundredths (7500, 7550); null when not a number with at most two decimals. */
function toHundredths(value: string): number | null {
    const trimmed = value.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) {
        return null;
    }

    return Math.round(Number(trimmed) * 100);
}
