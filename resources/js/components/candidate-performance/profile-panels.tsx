import { Award, ClipboardCheck, Scale } from 'lucide-react';
import { ConductLedgerTable } from '@/components/conduct/conduct-ledger-table';
import { ConductTotalsList } from '@/components/conduct/conduct-totals';
import { QualificationSummary } from '@/components/performance/qualification-summary';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel } from '@/components/ui/panel';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';
import type { ProfileConduct, ProfileQualification } from '@/types/candidate-performance';

interface QualificationPanelProps {
    qualification: ProfileQualification;
    /** The candidate's current class; null when not assigned. */
    classBatch: { id: number; name: string } | null;
}

/**
 * "Performance & Qualification" on the staff candidate profile: the
 * decision with its reasons, the overall score, every area's result and,
 * for staff with performance.view only, the class rank. Everything comes
 * from the server's QualificationEngine.
 */
export function CandidateQualificationPanel({ qualification, classBatch }: QualificationPanelProps) {
    const { areas, result, showRank, canConfigure } = qualification;
    const classTerm = terms.classBatch.singular.toLowerCase();

    return (
        <Panel
            title="Performance & Qualification"
            description={
                <>
                    Performance areas combine subject grades, the latest fitness test, conduct and attendance.
                    {showRank && classBatch !== null && ` The rank compares the final course grades within ${classBatch.name}.`}
                </>
            }
            actions={
                showRank &&
                classBatch !== null &&
                result !== null && (
                    <ButtonLink href={routes.qualification.index({ class: String(classBatch.id) })} variant="secondary">
                        Open Class Qualification
                    </ButtonLink>
                )
            }
        >
            {result === null ? (
                <EmptyState
                    icon={Award}
                    headingLevel="h3"
                    title="No qualification yet"
                    description={`Qualification is decided within a ${classTerm}. Assign the candidate to a ${classTerm} to see it.`}
                />
            ) : areas.length === 0 ? (
                <EmptyState
                    icon={Award}
                    headingLevel="h3"
                    title="No performance areas are configured"
                    description="Qualification cannot be decided until administrators set the areas, their weights and passing grades."
                    action={
                        canConfigure && (
                            <ButtonLink href={routes.performanceAreas.index()} variant="secondary">
                                Configure Performance Areas
                            </ButtonLink>
                        )
                    }
                />
            ) : (
                <QualificationSummary areas={areas} qualification={result} showRank={showRank} />
            )}
        </Panel>
    );
}

interface ConductPanelProps {
    conduct: ProfileConduct;
    candidateId: number;
}

/**
 * "Conduct" on the staff candidate profile: merit, demerit and net points
 * that count, and the latest entries (voided ones struck through and not
 * counted). Staff who may act on the candidate get a link to the full
 * record, where entries are recorded and voided.
 */
export function CandidateConductPanel({ conduct, candidateId }: ConductPanelProps) {
    return (
        <Panel
            title="Conduct"
            description="Merits and demerits. Voided entries are listed but not counted."
            actions={
                conduct.canManage && (
                    <ButtonLink href={routes.conduct.show(candidateId)} variant="secondary" icon={<Scale className="size-4" aria-hidden="true" />}>
                        Open Conduct Record
                    </ButtonLink>
                )
            }
            bodyClassName="p-0"
        >
            <div className="p-5">
                <ConductTotalsList totals={conduct.totals} />
            </div>
            {conduct.entries.length === 0 ? (
                <div className="border-t border-line">
                    <EmptyState
                        icon={ClipboardCheck}
                        headingLevel="h3"
                        title="No merits or demerits yet"
                        description={conduct.canManage ? 'Record them from the conduct record.' : 'Entries appear here once they are recorded.'}
                    />
                </div>
            ) : (
                <div className="border-t border-line">
                    <h3 className="px-5 pt-4 text-sm font-semibold text-ink">Latest Entries</h3>
                    <ConductLedgerTable entries={conduct.entries} caption="Latest merits and demerits" />
                </div>
            )}
        </Panel>
    );
}
