import { usePage } from '@inertiajs/react';
import { createContext, useCallback, useContext, useState } from 'react';

const STORAGE_PREFIX = 'collapsed:';

/**
 * Wrap a long page in `<StartCollapsed.Provider value>` to show its boxes
 * closed at first (e.g. the candidate profile); the user opens the ones
 * they need.
 */
export const StartCollapsed = createContext(false);

function readCollapsed(key: string, fallback: boolean): boolean {
    try {
        const stored = window.localStorage.getItem(STORAGE_PREFIX + key);

        return stored === null ? fallback : stored === '1';
    } catch {
        return fallback;
    }
}

function storeCollapsed(key: string, collapsed: boolean): void {
    try {
        window.localStorage.setItem(STORAGE_PREFIX + key, collapsed ? '1' : '0');
    } catch {
        // Storage may be blocked; the box still opens and closes.
    }
}

/**
 * Open/closed state of a titled box, opened and closed from its header.
 * The choice is remembered per device for the same box on the same kind of
 * page (e.g. "Attendance" on every candidate profile).
 */
export function useCollapsible(name: string, enabled: boolean, defaultCollapsed?: boolean) {
    const component = usePage().component;
    const startCollapsed = useContext(StartCollapsed);
    const key = `${component}:${name}`;
    const [collapsed, setCollapsed] = useState(() => enabled && readCollapsed(key, defaultCollapsed ?? startCollapsed));

    const toggle = useCallback(() => {
        setCollapsed((current) => {
            storeCollapsed(key, !current);

            return !current;
        });
    }, [key]);

    return { collapsed: enabled && collapsed, toggle };
}
