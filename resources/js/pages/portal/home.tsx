import { Head, usePage } from '@inertiajs/react';
import { Award, CalendarClock, CalendarDays, ClipboardList, Dumbbell, GraduationCap, HeartPulse, IdCard, PartyPopper, Users } from 'lucide-react';
import { QualificationBadge } from '@/components/performance/area-status';
import { ExamCard, type PortalExam } from '@/components/portal/exam-card';
import { PortalEmpty, PortalSection, PortalTile } from '@/components/portal/portal-ui';
import { StandingBadge } from '@/components/grading/standing';
import { ButtonLink } from '@/components/ui/button';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { formatCalendarDate, formatGrade, useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { Paginated } from '@/types';
import type { PortalPerformanceCard } from '@/types/candidate-performance';
import type { OverallStanding, StatusValue } from '@/types/grading';

interface HomeProps {
    summary: { name: string; number: string; className: string | null; period: string | null; eligible: boolean; subjectCount: number; overall: OverallStanding };
    /** Open now (the first few; the Examinations page lists all). */
    available: Paginated<PortalExam>;
    /** The next scheduled examinations. */
    upcoming: Paginated<PortalExam>;
    /** Own qualification status (never a rank); null without a class. */
    performance: PortalPerformanceCard | null;
    sections: {
        grades: { overall: OverallStanding; subjectCount: number; outstandingCount: number };
        examinations: { openCount: number; upcomingCount: number; releasedCount: number };
        fitness: { title: string; testedOn: string; status: StatusValue; points: number | null } | null;
        /** Own uploaded medical documents by review status. */
        medical: { documentCount: number; waitingCount: number; returnedCount: number };
    };
}

/**
 * Candidate home: what to do now (open examinations), what comes next, and
 * one tile per portal section with the single figure that matters. Details
 * live on each section's own page.
 */
export default function PortalHome({ summary, available, upcoming, performance, sections }: HomeProps) {
    // Military fitness is staff only unless the institution shows it to candidates.
    const { showFitness } = usePage().props.app.portal;
    const dates = useDateFormatter();

    return (
        <>
            <Head title="Home" />

            <section className="brand-dark mb-10 rounded-3xl bg-primary-800 px-6 py-8 text-white shadow-md sm:px-10 sm:py-10" aria-labelledby="welcome-title">
                <p className="text-sm font-bold uppercase tracking-[0.2em] text-accent-300">Welcome back</p>
                <h1 id="welcome-title" className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                    {summary.name}
                </h1>
                <ul className="mt-6 flex flex-wrap gap-3 text-sm">
                    <li className="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2">
                        <IdCard className="size-4 text-accent-300" aria-hidden="true" />
                        Candidate {summary.number}
                    </li>
                    <li className="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2">
                        <Users className="size-4 text-accent-300" aria-hidden="true" />
                        {summary.className ?? 'Class not assigned'}
                    </li>
                    {summary.period && (
                        <li className="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2">
                            <CalendarDays className="size-4 text-accent-300" aria-hidden="true" />
                            {summary.period}
                        </li>
                    )}
                </ul>
            </section>

            <div className="flex flex-col gap-10">
                <PortalSection
                    icon={ClipboardList}
                    title="Open Now"
                    description={available.total === 0 ? undefined : 'Examinations you can take right now.'}
                    action={
                        available.total > available.data.length && (
                            <ButtonLink href={routes.portal.examinations()} variant="secondary">
                                See All {available.total}
                            </ButtonLink>
                        )
                    }
                >
                    {available.data.length === 0 ? (
                        <PortalEmpty icon={PartyPopper} title="Nothing to take right now">
                            {summary.eligible
                                ? 'New quizzes and examinations appear here when your instructor opens them.'
                                : 'An eligible class assignment is needed to take examinations. Please contact the academic office.'}
                        </PortalEmpty>
                    ) : (
                        <div className="grid gap-5 md:grid-cols-2">
                            {available.data.map((exam) => (
                                <ExamCard key={exam.id} exam={exam} />
                            ))}
                        </div>
                    )}
                </PortalSection>

                {upcoming.data.length > 0 && (
                    <PortalSection icon={CalendarClock} title="Coming Up" flush>
                        <ul className="divide-y divide-line">
                            {upcoming.data.map((exam) => (
                                <li key={exam.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-7">
                                    <div className="min-w-0">
                                        <p className="text-base font-semibold text-ink">{exam.title}</p>
                                        <p className="text-sm text-ink-muted">{exam.subject}</p>
                                    </div>
                                    <p className="inline-flex items-center gap-2 text-sm font-medium text-ink">
                                        <CalendarClock className="size-4 text-primary-600" aria-hidden="true" />
                                        Opens {dates.dateTime(exam.opensAt)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </PortalSection>
                )}

                <section aria-labelledby="sections-title">
                    <h2 id="sections-title" className="mb-4 text-xl font-semibold text-primary-900">
                        My Records
                    </h2>
                    <div className={`grid gap-5 sm:grid-cols-2 ${showFitness ? 'lg:grid-cols-3' : 'xl:grid-cols-4'}`}>
                        <PortalTile href={routes.portal.grades()} icon={GraduationCap} title="My Grades" cta="View Grades">
                            <span className="flex flex-col items-start gap-2">
                                {sections.grades.overall.standing === null ? (
                                    <span className="text-ink-muted">No standing yet</span>
                                ) : (
                                    <StandingBadge standing={sections.grades.overall.standing} />
                                )}
                                <span className="text-ink-muted">
                                    {sections.grades.subjectCount} {sections.grades.subjectCount === 1 ? 'subject' : 'subjects'}
                                    {sections.grades.outstandingCount > 0 && ` · ${sections.grades.outstandingCount} awaiting a score`}
                                </span>
                            </span>
                        </PortalTile>

                        <PortalTile href={routes.portal.examinations()} icon={ClipboardList} title="Examinations" cta="View Examinations">
                            <span className="flex flex-col gap-1">
                                <span>
                                    <span className="text-2xl font-bold tabular-nums">{sections.examinations.openCount}</span> open now
                                </span>
                                <span className="text-ink-muted">
                                    {sections.examinations.upcomingCount} coming up · {sections.examinations.releasedCount}{' '}
                                    {sections.examinations.releasedCount === 1 ? 'result' : 'results'}
                                </span>
                            </span>
                        </PortalTile>

                        <PortalTile href={routes.portal.performance()} icon={Award} title="My Performance" cta="View Performance">
                            {performance === null ? (
                                <span className="text-ink-muted">Available once you are assigned to a class.</span>
                            ) : !performance.configured ? (
                                <span className="text-ink-muted">Not set up yet by the academic office.</span>
                            ) : (
                                <span className="flex flex-col items-start gap-2">
                                    <QualificationBadge status={performance.status} />
                                    <span className="text-ink-muted">{performanceHint(performance)}</span>
                                </span>
                            )}
                        </PortalTile>

                        {showFitness && (
                            <PortalTile href={routes.portal.fitness()} icon={Dumbbell} title="Physical Fitness" cta="View Fitness Tests">
                                {sections.fitness === null ? (
                                    <span className="text-ink-muted">No fitness tests yet.</span>
                                ) : (
                                    <span className="flex flex-col items-start gap-2">
                                        <StatusBadge tone={sections.fitness.status.tone as StatusTone}>{sections.fitness.status.label}</StatusBadge>
                                        <span className="text-ink-muted">
                                            {sections.fitness.title} · {formatCalendarDate(sections.fitness.testedOn)}
                                            {sections.fitness.points !== null && ` · ${formatGrade(sections.fitness.points)} pts`}
                                        </span>
                                    </span>
                                )}
                            </PortalTile>
                        )}

                        <PortalTile href={routes.portal.medical()} icon={HeartPulse} title="Medical" cta="Open Medical Records">
                            {sections.medical.documentCount === 0 ? (
                                <span className="text-ink-muted">No documents yet. Upload your medical certificate.</span>
                            ) : (
                                <span className="flex flex-col items-start gap-2">
                                    <span>
                                        <span className="text-2xl font-bold tabular-nums">{sections.medical.documentCount}</span>{' '}
                                        {sections.medical.documentCount === 1 ? 'document' : 'documents'} uploaded
                                    </span>
                                    {sections.medical.returnedCount > 0 && (
                                        <StatusBadge tone="danger">{sections.medical.returnedCount} returned: see the reason</StatusBadge>
                                    )}
                                    {sections.medical.waitingCount > 0 && <span className="text-ink-muted">{sections.medical.waitingCount} waiting for review</span>}
                                </span>
                            )}
                        </PortalTile>
                    </div>
                </section>
            </div>
        </>
    );
}

/** One short line under the qualification status. */
function performanceHint(performance: PortalPerformanceCard): string {
    switch (performance.status.value) {
        case 'qualified':
            return 'Every required area passed.';
        case 'not_qualified':
            return performance.reasons[0] ?? 'A required area is not met.';
        case 'pending':
            return performance.pending.length > 0 ? `Waiting for ${performance.pending.join(', ')}.` : 'Waiting for results.';
    }
}
