import { Head, Link, router } from '@inertiajs/react';
import { Award, ClipboardPen, Copy, Gauge, Layers, Scale } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { PieChart } from '@/components/charts/pie-chart';
import { StandingBadge, StandingRanges } from '@/components/grading/standing';
import { componentsText, CopyWeightsDialog } from '@/components/grading-setup/copy-weights-dialog';
import { SetupFlow, type FlowStep } from '@/components/grading-setup/setup-flow';
import { phaseName, unitsLabel } from '@/components/grading/course-record';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField, SelectInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { RowAction, Table, TableBody, TableHead, Td, Th, Tr } from '@/components/ui/table';
import { formatGrade } from '@/lib/format';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { AreasOverview, GradingSetupProps, SubjectWeightsRow, WorkedExample } from '@/types/grading-setup';

/** In-page links land on the panel heading, below the sticky top bar. */
const ANCHOR = 'scroll-mt-24';

/** "A", "A and B", "A, B and C". */
function listText(items: string[]): string {
    return items.length <= 1 ? (items[0] ?? '') : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/** Weights summed in the browser can carry floating-point noise (100.00000000000001). */
function roundTo2(value: number): number {
    return Math.round(value * 100) / 100;
}

export default function GradingSetup(props: GradingSetupProps) {
    const { period, periods, thresholds, subjectWeights, copySources, areas, sources, example, can } = props;
    const [copying, setCopying] = useState(false);
    const missing = subjectWeights.filter((row) => row.components.length === 0);
    const mustPass = areas.list.filter((area) => area.mustPass).length;
    const areaProblems = areas.emptySubjectAreas.length + areas.unmappedSubjects.length;

    const steps: FlowStep[] = [
        {
            href: '#example',
            title: 'Scores',
            description: 'Only finalized assessments count.',
            status: null,
            icon: ClipboardPen,
        },
        {
            href: '#weights',
            title: 'Subject Grade',
            description: 'Weighted components give a grade out of 100.',
            status:
                subjectWeights.length === 0
                    ? { ready: false, text: 'No subjects yet' }
                    : missing.length === 0
                      ? { ready: true, text: `All ${subjectWeights.length} subjects have weights` }
                      : { ready: false, text: `${missing.length} of ${subjectWeights.length} subjects have no weights` },
            icon: Scale,
        },
        {
            href: '#standing',
            title: 'Subject Standing',
            description: 'Passing and Warning Grades give Passing, At Risk or Failing.',
            status:
                thresholds === null
                    ? { ready: false, text: 'Passing and Warning Grades not set' }
                    : { ready: true, text: `Passing ${formatGrade(thresholds.passingGrade)} · Warning ${formatGrade(thresholds.warningGrade)}` },
            icon: Gauge,
        },
        {
            href: '#areas',
            title: 'Performance Areas',
            description: 'Weighted areas give the final course grade.',
            status:
                areas.list.length === 0
                    ? { ready: false, text: 'No active areas' }
                    : areaProblems > 0
                      ? { ready: false, text: `${areas.list.length} areas · ${areaProblems} to check` }
                      : { ready: true, text: `${areas.list.length} active areas` },
            icon: Layers,
        },
        {
            href: '#qualification',
            title: 'Qualification & Rank',
            description: 'Must-pass areas decide qualification. The final course grade sets class rank.',
            status:
                areas.list.length === 0
                    ? null
                    : mustPass === 0
                      ? { ready: false, text: 'No must-pass area: all Qualified' }
                      : { ready: true, text: `${mustPass} must-pass ${mustPass === 1 ? 'area' : 'areas'}` },
            icon: Award,
        },
    ];

    return (
        <>
            <Head title="Grading Setup" />

            <PageHeader title="Grading Setup" description="How grades, standing and qualification are decided." />

            {period === null ? (
                <div className="rounded-lg border border-line bg-surface">
                    <EmptyState
                        icon={Scale}
                        title="No academic periods yet"
                        description={`Create a period with ${terms.classBatch.plural.toLowerCase()} and subjects first.`}
                        action={
                            can.managePeriods && (
                                <ButtonLink href={routes.academicPeriods.create()} variant="primary">
                                    Create Academic Period
                                </ButtonLink>
                            )
                        }
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    {periods.length > 1 && (
                        <FormField label="Academic Period" className="md:w-80">
                            <SelectInput value={String(period.id)} onChange={(event) => router.get(routes.gradingSetup.index({ period: event.target.value }))}>
                                {periods.map((option) => (
                                    <option key={option.id} value={String(option.id)}>
                                        {option.name}
                                        {option.isActive ? ' (active)' : ''}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    )}

                    <section aria-labelledby="how-heading" className="flex flex-col gap-3">
                        <h2 id="how-heading" className="text-lg font-semibold text-ink">
                            How Results Are Decided
                        </h2>
                        <SetupFlow steps={steps} />
                    </section>

                    <Example example={example} hasThresholds={thresholds !== null} />

                    <SubjectWeights
                        period={period}
                        rows={subjectWeights}
                        missing={missing}
                        hasSources={copySources.length > 0}
                        onCopy={() => setCopying(true)}
                    />

                    <Panel
                        id="standing"
                        className={ANCHOR}
                        collapsible={false}
                        title="Step 3 · Passing and Warning Grades"
                        description={`Set once for ${period.name}. They decide each subject’s standing.`}
                        actions={
                            can.setThresholds && (
                                <ButtonLink href={routes.academicPeriods.thresholds(period.id, { return: 'setup' })} variant="secondary">
                                    {thresholds === null ? 'Set Passing and Warning Grades' : 'Change Passing and Warning Grades'}
                                </ButtonLink>
                            )
                        }
                    >
                        {thresholds === null ? (
                            <Alert tone="warning" title="Not set yet">
                                Grades are calculated, but no standing is shown.
                                {!can.setThresholds && ' They apply to every campus and are set by the institution administrator.'}
                            </Alert>
                        ) : (
                            <StandingRanges passingHundredths={Math.round(thresholds.passingGrade * 100)} warningHundredths={Math.round(thresholds.warningGrade * 100)} />
                        )}
                    </Panel>

                    <Areas areas={areas} canConfigure={can.configurePerformance} />

                    <Sources sources={sources} areas={areas} can={can} />

                    <PassingGradesExplained />
                </div>
            )}

            {period !== null && (
                <CopyWeightsDialog open={copying} sources={copySources} targets={missing} periodId={period.id} onClose={() => setCopying(false)} />
            )}
        </>
    );
}

function SubjectWeights({
    period,
    rows,
    missing,
    hasSources,
    onCopy,
}: {
    period: { id: number; name: string };
    rows: SubjectWeightsRow[];
    missing: SubjectWeightsRow[];
    hasSources: boolean;
    onCopy: () => void;
}) {
    const classTerm = terms.classBatch.singular;
    // Copy Weights needs a subject that is set up and one that is not.
    const canCopy = hasSources && missing.length > 0;

    return (
        <Panel
            id="weights"
            className={ANCHOR}
            collapsible={false}
            title="Step 2 · Subject Weights"
            description="Weights must total 100%. Instructors can create assessments only in a subject with weights."
            bodyClassName="p-0"
            actions={
                canCopy && (
                    <Button variant="secondary" icon={<Copy className="size-4" aria-hidden="true" />} onClick={onCopy}>
                        Copy Weights
                    </Button>
                )
            }
        >
            {missing.length > 0 && (
                <div className="border-b border-line px-5 py-4">
                    <Alert tone="warning" title={`${missing.length} ${missing.length === 1 ? 'subject has' : 'subjects have'} no weights yet`}>
                        {hasSources ? 'Use Copy Weights or Set Weights.' : 'Set Weights on one subject, then Copy Weights to the rest.'}
                    </Alert>
                </div>
            )}
            {rows.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    headingLevel="h3"
                    title={`No subjects in ${period.name} yet`}
                    description={`Add them in ${terms.classBatch.plural} → a ${classTerm.toLowerCase()} → Add Subject.`}
                />
            ) : (
                <Table caption={`Subject weights in ${period.name}`} className="min-w-[40rem]">
                    <TableHead>
                        <Th>{classTerm}</Th>
                        <Th>Subject</Th>
                        <Th>Components and Weights</Th>
                        <Th align="right" className="hidden md:table-cell">
                            Finalized
                        </Th>
                        <Th align="right">
                            <span className="sr-only">Actions</span>
                        </Th>
                    </TableHead>
                    <TableBody>
                        {rows.map((row) => (
                            <Tr key={row.classSubjectId}>
                                <Td className="text-ink">{row.classBatch.name}</Td>
                                <Td className="font-medium text-ink">
                                    {row.subject.name}
                                    <span className="block text-xs font-normal text-ink-muted">
                                        {phaseName(row.phase)} · {unitsLabel(row.units)}
                                    </span>
                                </Td>
                                <Td>
                                    {row.components.length === 0 ? (
                                        <StatusBadge tone="warning">No Weights Yet</StatusBadge>
                                    ) : (
                                        <span className="text-sm text-ink">{componentsText(row.components)}</span>
                                    )}
                                </Td>
                                <Td align="right" numeric className="hidden text-ink md:table-cell">
                                    {row.finalizedCount} of {row.assessmentCount}
                                </Td>
                                <Td align="right">
                                    <RowAction
                                        href={routes.classes.grading(row.classBatch.id, row.classSubjectId, { return: 'setup' })}
                                        label={`${row.components.length === 0 ? 'Set Weights' : 'Edit Weights'} for ${row.subject.name}, ${row.classBatch.name}`}
                                    >
                                        {row.components.length === 0 ? 'Set Weights' : 'Edit Weights'}
                                    </RowAction>
                                </Td>
                            </Tr>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}

/** The grade engine's own result for sample scores, so the rule is shown, not described. */
function Example({ example, hasThresholds }: { example: WorkedExample; hasThresholds: boolean }) {
    return (
        <Panel
            id="example"
            className={ANCHOR}
            collapsible={false}
            title="Steps 1–2 · How Scores Become a Subject Grade"
            description={example.source === null ? 'Sample weights and results.' : `Sample results with the weights of ${example.source}.`}
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div className="flex flex-col gap-4">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[28rem] text-left text-sm">
                            <caption className="sr-only">Example subject grade</caption>
                            <thead className="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th scope="col" className="px-3 py-2">
                                        Component
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right">
                                        Weight
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right">
                                        Sample Result
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right">
                                        Points
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {example.components.map((component) => (
                                    <tr key={component.name}>
                                        <th scope="row" className="px-3 py-2 font-medium text-ink">
                                            {component.name}
                                        </th>
                                        <td className="px-3 py-2 text-right tabular-nums">{component.weight}%</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{formatGrade(component.result)}%</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{formatGrade(component.points)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-line-strong">
                                    <th scope="row" colSpan={3} className="px-3 py-2 text-right font-semibold text-ink">
                                        Subject grade
                                    </th>
                                    <td className="px-3 py-2 text-right text-base font-semibold tabular-nums text-ink">{formatGrade(example.grade)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm text-ink">
                        <li>Points = result × weight ÷ 100. Subject grade = sum of points.</li>
                        {hasThresholds && example.standing !== null && (
                            <li>
                                In this period, {formatGrade(example.grade)} is <StandingBadge standing={example.standing} />.
                            </li>
                        )}
                        {example.partial !== null && (
                            <li>
                                With only {listText(example.partial.assessed)} assessed ({example.partial.assessedWeight}% of the weight), the grade in
                                progress is <strong className="tabular-nums">{formatGrade(example.partial.grade)}</strong>.
                            </li>
                        )}
                        <li>Missing scores are left out, never counted as zero.</li>
                    </ul>
                </div>
                {example.components.length > 1 && (
                    <PieChart
                        slices={example.components.map((component) => ({ label: component.name, value: component.weight }))}
                        noun={{ one: 'of the grade', other: 'of the grade' }}
                        formatValue={(value) => `${roundTo2(value)}%`}
                        showShare={false}
                    />
                )}
            </div>
        </Panel>
    );
}

function Areas({ areas, canConfigure }: { areas: AreasOverview; canConfigure: boolean }) {
    return (
        <Panel
            id="areas"
            className={ANCHOR}
            collapsible={false}
            title="Steps 4–5 · Performance Areas and Qualification"
            description={`Set once for all periods and ${terms.classBatch.plural.toLowerCase()}.`}
            bodyClassName={areas.list.length === 0 ? undefined : 'p-0'}
            actions={
                canConfigure && (
                    <ButtonLink href={routes.performanceAreas.index()} variant="secondary">
                        Edit Performance Areas
                    </ButtonLink>
                )
            }
        >
            {areas.list.length === 0 ? (
                <Alert tone="warning" title="No active performance areas">
                    No final course grade; every candidate is Pending.
                </Alert>
            ) : (
                <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="flex flex-col">
                        <Table caption="Active performance areas" className="min-w-[34rem]">
                            <TableHead>
                                <Th>Area</Th>
                                <Th>Grade Taken From</Th>
                                <Th align="right">Share of Final Grade</Th>
                                <Th align="right">Area Passing Grade</Th>
                                <Th>Must Pass</Th>
                            </TableHead>
                            <TableBody>
                                {areas.list.map((area) => (
                                    <Tr key={area.id}>
                                        <Td className="font-medium text-ink">{area.name}</Td>
                                        <Td className="text-ink">
                                            {area.source.label}
                                            {area.subjects.length > 0 && <span className="block text-xs text-ink-muted">{area.subjects.join(', ')}</span>}
                                            {area.conductRule !== null && (
                                                <span className="block text-xs text-ink-muted">
                                                    Base {area.conductRule.base} · +{area.conductRule.merit} per merit point · −{area.conductRule.demerit} per
                                                    demerit point
                                                </span>
                                            )}
                                        </Td>
                                        <Td align="right" numeric>
                                            {area.share}%<span className="block text-xs text-ink-muted">weight {area.weight}</span>
                                        </Td>
                                        <Td align="right" numeric>
                                            {area.passingGrade}
                                        </Td>
                                        <Td>{area.mustPass ? <StatusBadge tone="info">Must Pass</StatusBadge> : <span className="text-ink-muted">No</span>}</Td>
                                    </Tr>
                                ))}
                            </TableBody>
                        </Table>
                        <div id="qualification" className={`flex flex-col gap-1.5 px-5 py-4 text-sm text-ink ${ANCHOR}`}>
                            <p>
                                <strong>Final course grade</strong>: weighted average of the graded areas; Partial while a weighted area has no grade.
                            </p>
                            <p>
                                <strong>Qualified</strong>: all must-pass areas passed · <strong>Not Qualified</strong>: one failed · <strong>Pending</strong>:
                                one is incomplete or has no results yet.
                            </p>
                            <p>
                                <strong>Class rank</strong>: by final course grade, staff only.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-4 px-5 py-4 lg:pl-0">
                        {areas.list.length > 1 && (
                            <PieChart
                                slices={areas.list.map((area) => ({ label: area.name, value: Number(area.weight) }))}
                                noun={{ one: 'total weight', other: 'total weight' }}
                                formatValue={(value) => String(roundTo2(value))}
                            />
                        )}
                        <Checks areas={areas} />
                    </div>
                </div>
            )}
        </Panel>
    );
}

function Checks({ areas }: { areas: AreasOverview }) {
    const notes: ReactNode[] = [];
    if (areas.unmappedSubjects.length > 0) {
        notes.push(<>Subjects in no area (left out of qualification): {listText(areas.unmappedSubjects)}. Add them to a Subject Grades area.</>);
    }
    if (areas.emptySubjectAreas.length > 0) {
        notes.push(<>Subject Grades areas without subjects (no grade): {listText(areas.emptySubjectAreas)}.</>);
    }
    if (!areas.hasMustPass) {
        notes.push(<>No must-pass area: every candidate is Qualified.</>);
    }

    if (notes.length === 0) {
        return null;
    }

    return (
        <Alert tone="warning" title="To check">
            <ul className="flex list-disc flex-col gap-1 pl-4">
                {notes.map((note, index) => (
                    <li key={index}>{note}</li>
                ))}
            </ul>
        </Alert>
    );
}

function Sources({ sources, areas, can }: { sources: GradingSetupProps['sources']; areas: AreasOverview; can: GradingSetupProps['can'] }) {
    const area = (value: string) => areas.list.find((candidate) => candidate.source.value === value) ?? null;
    const fitness = area('fitness');
    const conduct = area('conduct');
    const attendance = area('attendance');

    return (
        <Panel collapsible={false} title="Where the Other Area Grades Come From">
            <div className="grid gap-4 md:grid-cols-3">
                <SourceCard
                    title="Military Fitness"
                    rule="Points in the class’s latest fitness test with results. Any failed event fails the area."
                    detail={`${sources.fitnessEvents} active ${sources.fitnessEvents === 1 ? 'event' : 'events'}`}
                    area={fitness === null ? null : `${fitness.name}: passing grade ${fitness.passingGrade}`}
                    link={can.configureFitness ? { href: routes.fitness.standards.index(), label: 'Events and Points' } : null}
                />
                <SourceCard
                    title="Merits & Demerits"
                    rule={
                        conduct?.conductRule
                            ? `Rating = ${conduct.conductRule.base} + ${conduct.conductRule.merit} per merit point − ${conduct.conductRule.demerit} per demerit point (0–100).`
                            : 'Rating = base + merit points × merit value − demerit points × demerit value (0–100).'
                    }
                    detail={`${sources.conductTypes} active ${sources.conductTypes === 1 ? 'type' : 'types'}`}
                    area={conduct === null ? null : `${conduct.name}: passing grade ${conduct.passingGrade}`}
                    link={can.configurePerformance ? { href: routes.conduct.types.index(), label: 'Merit & Demerit Types' } : null}
                />
                <SourceCard
                    title="Attendance"
                    rule="Rate = (present + late) ÷ (present + late + absent) × 100. Excused and unrecorded sessions don’t count."
                    detail="Recorded per session"
                    area={attendance === null ? null : `${attendance.name}: passing grade ${attendance.passingGrade}`}
                    link={can.manageAttendance ? { href: routes.attendance.index(), label: 'Attendance' } : null}
                />
            </div>
        </Panel>
    );
}

function SourceCard({
    title,
    rule,
    detail,
    area,
    link,
}: {
    title: string;
    rule: string;
    detail: string;
    area: string | null;
    link: { href: string; label: string } | null;
}) {
    return (
        <section aria-label={title} className="flex flex-col gap-2 rounded-lg border border-line px-4 py-3">
            <h3 className="font-semibold text-ink">{title}</h3>
            <p className="text-sm text-ink">{rule}</p>
            <p className="text-sm text-ink-muted">
                {detail}
                {' · '}
                {area ?? 'No active area uses it'}
            </p>
            {link !== null && (
                <Link href={link.href} className="mt-auto text-sm font-medium text-primary-700 underline">
                    {link.label}
                </Link>
            )}
        </section>
    );
}

/** The three "passing" numbers of the system and what each one decides. */
function PassingGradesExplained() {
    const rows = [
        {
            name: 'Passing and Warning Grades',
            where: 'Step 3, per academic period',
            decides: 'Subject standing: Passing, At Risk or Failing.',
        },
        {
            name: 'Area passing grade',
            where: 'Step 4, per performance area',
            decides: 'Area passed or not. Must-pass areas decide qualification.',
        },
        {
            name: 'Examination passing score',
            where: 'Each quiz or examination',
            decides: 'That attempt’s Passed or Failed only.',
        },
    ];

    return (
        <Panel
            collapsible={false}
            title="Which Passing Grade Does What"
            description="Changing one never changes the others."
            bodyClassName="p-0"
        >
            <Table caption="The three passing settings" className="min-w-[36rem]">
                <TableHead>
                    <Th>Setting</Th>
                    <Th>Where It Is Set</Th>
                    <Th>What It Decides</Th>
                </TableHead>
                <TableBody>
                    {rows.map((row) => (
                        <Tr key={row.name}>
                            <Td className="font-medium text-ink">{row.name}</Td>
                            <Td className="text-ink">{row.where}</Td>
                            <Td className="text-ink">{row.decides}</Td>
                        </Tr>
                    ))}
                </TableBody>
            </Table>
        </Panel>
    );
}
