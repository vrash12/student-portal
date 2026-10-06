import { Megaphone, Star } from 'lucide-react';
import { PortalSection } from '@/components/portal/portal-ui';
import { cn } from '@/lib/cn';
import { useDateFormatter } from '@/lib/format';
import type { PortalNotice } from '@/types/announcements';

/**
 * Notices from the institution meant for the candidate (owner request,
 * 2026-10-06), important ones first. Hidden when there are none.
 */
export function PortalNotices({ notices }: { notices: PortalNotice[] }) {
    const dates = useDateFormatter();

    if (notices.length === 0) {
        return null;
    }

    return (
        <PortalSection icon={Megaphone} title="Notices" description="Announcements from your instructors and the academic office." flush>
            <ul className="divide-y divide-line">
                {notices.map((notice) => (
                    <li key={notice.id} className={cn('px-5 py-5 sm:px-7', notice.important && 'border-l-4 border-accent-400 bg-accent-50/60')}>
                        <article aria-labelledby={`notice-${notice.id}`}>
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h3 id={`notice-${notice.id}`} className="text-base font-semibold text-ink">
                                    {notice.title}
                                </h3>
                                {notice.important && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-accent-300 px-2.5 py-0.5 text-xs font-bold text-primary-900">
                                        <Star className="size-3" aria-hidden="true" />
                                        Important
                                    </span>
                                )}
                            </div>
                            <p className="mt-2 whitespace-pre-line text-base leading-relaxed text-ink">{notice.body}</p>
                            <p className="mt-3 text-sm text-ink-muted">
                                {notice.postedBy} · To {notice.audience} · {dates.dateTime(notice.postedAt)}
                                {notice.expiresAt !== null && ` · Until ${dates.dateTime(notice.expiresAt)}`}
                            </p>
                        </article>
                    </li>
                ))}
            </ul>
        </PortalSection>
    );
}
