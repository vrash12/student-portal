import { createContext } from 'react';

/**
 * The full-width place at the top of the staff content area where the page
 * title banner is shown, edge to edge (owner request, 2026-10-03), whatever
 * width the page's own content uses. Null outside the staff layout: the
 * banner then stays where the page renders it.
 */
export const PageHeaderSlot = createContext<HTMLElement | null>(null);
