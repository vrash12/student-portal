import { usePage } from '@inertiajs/react';
import { CircleCheck, CircleX, Info, TriangleAlert, X, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/cn';
import type { Toast, ToastTone } from '@/types';

interface ToastEntry extends Toast {
    id: number;
}

const toneStyles: Record<ToastTone, { icon: LucideIcon; classes: string }> = {
    success: { icon: CircleCheck, classes: 'border-success-border text-success-fg' },
    error: { icon: CircleX, classes: 'border-danger-border text-danger-fg' },
    warning: { icon: TriangleAlert, classes: 'border-warning-border text-warning-fg' },
    info: { icon: Info, classes: 'border-info-border text-info-fg' },
};

const MAX_VISIBLE = 3;

interface ToasterProps {
    position?: 'bottom' | 'top';
}

/**
 * Unobtrusive notifications from server flash data (UI_UX_DESIGN.md §50).
 * The live region is always mounted so screen readers announce new toasts.
 */
export function Toaster({ position = 'bottom' }: ToasterProps) {
    const { flash } = usePage();
    const [toasts, setToasts] = useState<ToastEntry[]>([]);
    const nextId = useRef(0);

    const dismiss = useCallback((id: number) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    useEffect(() => {
        const toast = flash?.toast;
        if (toast === undefined) {
            return;
        }

        nextId.current += 1;
        const entry: ToastEntry = { ...toast, id: nextId.current };
        setToasts((current) => [...current.slice(-(MAX_VISIBLE - 1)), entry]);
    }, [flash]);

    return (
        <div
            aria-live="polite"
            className={cn(
                'pointer-events-none fixed inset-x-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end',
                position === 'bottom' ? 'bottom-0' : 'top-0',
            )}
        >
            {toasts.map((toast) => (
                <ToastItem key={toast.id} toast={toast} onDismiss={dismiss} />
            ))}
        </div>
    );
}

function ToastItem({ toast, onDismiss }: { toast: ToastEntry; onDismiss: (id: number) => void }) {
    const { icon: Icon, classes } = toneStyles[toast.type];

    const [paused, setPaused] = useState(false);

    // Errors stay until dismissed; other notices close after 5 seconds unless
    // the pointer or keyboard focus is on them.
    useEffect(() => {
        if (toast.type === 'error' || paused) {
            return;
        }
        const timer = window.setTimeout(() => onDismiss(toast.id), 5000);

        return () => window.clearTimeout(timer);
    }, [toast.id, toast.type, paused, onDismiss]);

    return (
        <div
            role={toast.type === 'error' ? 'alert' : undefined}
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocus={() => setPaused(true)}
            onBlur={() => setPaused(false)}
            className={cn(
                'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-surface p-4 shadow-md',
                classes,
            )}
        >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p className="flex-1 text-sm font-medium text-ink">{toast.message}</p>
            <button
                type="button"
                onClick={() => onDismiss(toast.id)}
                className="-m-1 flex size-8 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-neutral-bg hover:text-ink pointer-coarse:size-11"
                aria-label="Dismiss notification"
            >
                <X className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}
