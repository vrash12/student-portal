import type { PermissionCode } from '@/lib/permissions';

export type { PermissionCode };

export interface AuthUser {
    id: number;
    name: string;
    username: string;
    role: {
        code: string;
        name: string;
    };
    /**
     * The campus a staff account is limited to; null for accounts that see
     * every campus (and candidates, whose campus is on their record).
     */
    campus: CampusSummary | null;
    /** Candidate accounts only: shown in the portal header. */
    candidate?: { firstName: string; photoUrl: string | null } | null;
}

export interface CampusSummary {
    id: number;
    name: string;
    code: string;
}

/** A campus in a filter or picker (App\Support\CampusScope options). */
export interface CampusOption extends CampusSummary {
    isActive: boolean;
}

export interface Auth {
    user: AuthUser | null;
    /** Used only to decide what to show. The server authorizes every request. */
    permissions: PermissionCode[];
}

export interface AppBranding {
    name: string;
    shortName: string;
    organizationName: string;
    logoUrl: string | null;
    loginImageUrl: string | null;
    /** Background for smaller screens; null uses loginImageUrl everywhere. */
    loginCompactImageUrl: string | null;
    /** Sign-in page texts from configuration. */
    login: {
        headerTitle: string;
        headerSubtitle: string;
        /** Title of the sign-in card (defaults to the system name). */
        cardTitle: string;
        coreValues: string[];
        motto: string | null;
        tagline: string | null;
        /** Help desk contact shown by "Need assistance?"; null: contact the system administrator. */
        helpDesk: string | null;
    };
    /** Small "Powered by" credit on the sign-in page; null hides it. */
    poweredBy: { name: string; logoUrl: string | null } | null;
    /** Candidate portal pages the institution may turn off. */
    portal: {
        /** Physical Fitness page and Home tile; off by default (military fitness is staff only). */
        showFitness: boolean;
    };
    timezone: string;
    /** ISO 4217 code for Statements of Account, e.g. "PHP". */
    currency: string;
}

export interface SharedProps {
    app: AppBranding;
    auth: Auth;
}

export type ToastTone = 'success' | 'error' | 'warning' | 'info';

export interface Toast {
    type: ToastTone;
    message: string;
}

export interface FlashData {
    toast?: Toast;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Shape of Laravel's LengthAwarePaginator when serialized. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
    prev_page_url: string | null;
    next_page_url: string | null;
}
