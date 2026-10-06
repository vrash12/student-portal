import type { StatusTone } from '@/components/ui/status-badge';

/** Mirror of App\Enums\AnnouncementAudience. */
export type AnnouncementAudienceValue = 'everyone' | 'campus' | 'class';

/** Mirror of App\Enums\AnnouncementStatus (derived from the dates; never stored). */
export type AnnouncementStatusValue = 'scheduled' | 'current' | 'expired' | 'withdrawn';

export interface AnnouncementStatus {
    value: AnnouncementStatusValue;
    label: string;
    tone: StatusTone;
}

export interface AudienceOption {
    value: AnnouncementAudienceValue;
    label: string;
    description: string;
}

/** One notice in the staff list (AnnouncementPresenter::staffRow). */
export interface AnnouncementListItem {
    id: number;
    title: string;
    body: string;
    important: boolean;
    audience: { value: AnnouncementAudienceValue; label: string };
    status: AnnouncementStatus;
    /** ISO timestamps. */
    publishesAt: string;
    expiresAt: string | null;
    withdrawnAt: string | null;
    postedBy: string;
    /** Whether this user may change or withdraw it. */
    canManage: boolean;
}

/** A notice on the candidate's portal (AnnouncementPresenter::forCandidate). */
export interface PortalNotice {
    id: number;
    title: string;
    body: string;
    important: boolean;
    /** ISO timestamps. */
    postedAt: string;
    expiresAt: string | null;
    /** "Every candidate", the campus, or the class. */
    audience: string;
    postedBy: string;
}
