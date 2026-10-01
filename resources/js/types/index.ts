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
    /** Small "Powered by" credit on the sign-in page; null hides it. */
    poweredBy: { name: string; logoUrl: string | null } | null;
    timezone: string;
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
