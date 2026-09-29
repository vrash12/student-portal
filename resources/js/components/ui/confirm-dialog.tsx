import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    /** Describe exactly what will happen; avoid a bare "Are you sure?" (§42). */
    description: ReactNode;
    confirmLabel: string;
    cancelLabel?: string;
    tone?: 'danger' | 'primary';
    processing?: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Confirmation built on the native <dialog> element, which provides focus
 * trapping, Escape handling, and an inert background without extra libraries.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancel',
    tone = 'danger',
    processing = false,
    onConfirm,
    onCancel,
}: ConfirmDialogProps) {
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
            aria-describedby={descriptionId}
            onCancel={(event) => {
                event.preventDefault();
                if (!processing) {
                    onCancel();
                }
            }}
            className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line bg-surface p-0 text-ink shadow-lg"
        >
            <div className="p-6">
                <h2 id={titleId} className="text-lg font-semibold">
                    {title}
                </h2>
                <div id={descriptionId} className="mt-2 space-y-2 text-sm text-ink-muted">
                    {description}
                </div>
            </div>
            <div className="flex flex-col-reverse gap-2 rounded-b-xl border-t border-line bg-surface-muted px-6 py-4 sm:flex-row sm:justify-end">
                {/* Cancel receives initial focus so Enter never confirms by accident. */}
                <Button variant="secondary" onClick={onCancel} disabled={processing} autoFocus>
                    {cancelLabel}
                </Button>
                <Button variant={tone} onClick={onConfirm} loading={processing}>
                    {confirmLabel}
                </Button>
            </div>
        </dialog>
    );
}
