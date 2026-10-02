import { X } from 'lucide-react';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

interface DialogProps {
    open: boolean;
    title: string;
    description?: ReactNode;
    children: ReactNode;
    /** Buttons shown in the footer, e.g. Cancel and the primary action. */
    footer?: ReactNode;
    /** While true, Escape and the close button are ignored (e.g. during a save). */
    busy?: boolean;
    size?: 'md' | 'lg';
    onClose: () => void;
}

/**
 * Modal for small focused forms and previews (UI_UX_DESIGN.md §41), built on
 * the native <dialog> element for focus trapping, Escape handling, and an
 * inert background. Larger workflows belong on their own page.
 */
export function Dialog({ open, title, description, children, footer, busy = false, size = 'md', onClose }: DialogProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={titleId}
            aria-describedby={description ? descriptionId : undefined}
            onCancel={(event) => {
                event.preventDefault();
                if (!busy) {
                    onClose();
                }
            }}
            className={cn(
                'm-auto w-[calc(100%-2rem)] rounded-xl border border-line-box bg-surface p-0 text-ink shadow-lg',
                size === 'lg' ? 'max-w-2xl' : 'max-w-lg',
            )}
        >
            {open && (
                <>
                    <div className="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
                        <div className="min-w-0">
                            <h2 id={titleId} className="text-lg font-semibold">
                                {title}
                            </h2>
                            {description && (
                                <div id={descriptionId} className="mt-1 text-sm text-ink-muted">
                                    {description}
                                </div>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={busy}
                            className="-m-2 flex size-10 shrink-0 items-center justify-center rounded-md text-ink-muted hover:bg-neutral-bg hover:text-ink disabled:opacity-50 pointer-coarse:size-11"
                            aria-label="Close dialog"
                        >
                            <X className="size-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div className="max-h-[70dvh] overflow-y-auto px-6 py-5">{children}</div>
                    {footer && (
                        <div className="flex flex-col-reverse gap-2 rounded-b-xl border-t border-line bg-surface-muted px-6 py-4 sm:flex-row sm:justify-end">
                            {footer}
                        </div>
                    )}
                </>
            )}
        </dialog>
    );
}
