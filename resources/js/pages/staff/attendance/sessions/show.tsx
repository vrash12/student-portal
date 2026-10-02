import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, ScanLine, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AttendanceCountCards } from '@/components/attendance/attendance-count-cards';
import { formatHours } from '@/components/attendance/attendance-status';
import { QrScanner } from '@/components/attendance/qr-scanner';
import { RollCall } from '@/components/attendance/roll-call';
import { Alert } from '@/components/ui/alert';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { AttendanceSessionDetails, AttendanceStatusOption, RollCallCounts, RollCallRow } from '@/types/attendance';

interface AttendanceSessionShowProps {
    session: AttendanceSessionDetails & { createdBy: string };
    rows: RollCallRow[];
    counts: RollCallCounts;
    statusOptions: AttendanceStatusOption[];
    can: { delete: boolean; viewAllCandidates: boolean };
    /** Where the QR scanner sends each code read by the camera. */
    scanUrl: string;
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

export default function AttendanceSessionShow({ session, rows, counts, statusOptions, can, scanUrl, today }: AttendanceSessionShowProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [scanning, setScanning] = useState(false);
    const recordable = rows.filter((row) => row.recordable).length;
    const recordedOfRoll = recordable - counts.unrecorded;

    const remove = () => {
        router.delete(routes.attendance.sessions.destroy(session.id), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={session.title} />

            <PageHeader
                title={session.title}
                description={`${session.classBatch.name} · ${session.classBatch.period} · ${formatCalendarDate(session.heldOn)} · ${formatHours(session.hours)}`}
                breadcrumbs={[{ label: 'Attendance', href: routes.attendance.index() }, { label: session.title }]}
                actions={
                    <>
                        {can.delete && (
                            <Button variant="secondary" icon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setConfirmingDelete(true)}>
                                Delete Session
                            </Button>
                        )}
                        <ButtonLink href={routes.attendance.sessions.edit(session.id)} icon={<Pencil className="size-4" aria-hidden="true" />}>
                            Edit Details
                        </ButtonLink>
                        <Button icon={<ScanLine className="size-4" aria-hidden="true" />} onClick={() => setScanning(true)}>
                            Scan QR Codes
                        </Button>
                    </>
                }
            />

            <div className="flex flex-col gap-6">
                {errors.session !== undefined && (
                    <Alert tone="danger" title="The session was not deleted">
                        {errors.session}
                    </Alert>
                )}

                <Panel
                    title="Summary"
                    description={`${recordedOfRoll} of ${recordable} ${recordable === 1 ? 'candidate' : 'candidates'} recorded. Present and late count as attended; excused is left out of the attendance rate.`}
                >
                    <div className="flex flex-col gap-4">
                        <AttendanceCountCards options={statusOptions} counts={counts} unrecorded={counts.unrecorded} />
                        {session.notes && (
                            <p className="text-sm text-ink-muted">
                                <span className="font-medium text-ink">Notes:</span> {session.notes}
                            </p>
                        )}
                        <p className="text-xs text-ink-subtle">Created by {session.createdBy}. Every change to the attendance is kept in the audit log.</p>
                    </div>
                </Panel>

                <Panel
                    title="Roll Call"
                    description="Choose Present, Late, Excused or Absent for each candidate and add remarks where needed, or use Scan QR Codes to record arrivals with the camera. Only changed rows are saved."
                    bodyClassName="p-0"
                >
                    <RollCall sessionId={session.id} rows={rows} options={statusOptions} linkAllCandidates={can.viewAllCandidates} />
                </Panel>
            </div>

            <QrScanner
                open={scanning}
                session={session}
                scanUrl={scanUrl}
                counts={counts}
                today={today}
                onClose={() => {
                    setScanning(false);
                    // Show the scans on the roll call and the summary.
                    router.reload({ only: ['rows', 'counts', 'can'] });
                }}
            />

            <ConfirmDialog
                open={confirmingDelete}
                title={`Delete ${session.title}?`}
                description={<p>The session has no recorded attendance. Deleting it removes the session; the deletion is kept in the audit log.</p>}
                confirmLabel="Delete Session"
                processing={deleting}
                onConfirm={remove}
                onCancel={() => setConfirmingDelete(false)}
            />
        </>
    );
}
