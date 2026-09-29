import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Button, type ButtonSize, type ButtonVariant } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

interface ConfirmActionProps {
    /** Visible button content. */
    children: ReactNode;
    /** Accessible name when the visible content alone is ambiguous. */
    ariaLabel?: string;
    variant?: ButtonVariant;
    size?: ButtonSize;
    icon?: ReactNode;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    tone?: 'danger' | 'primary';
    method: 'post' | 'delete';
    href: string;
    disabled?: boolean;
}

/**
 * A button that asks for explicit confirmation before sending a request
 * (UI_UX_DESIGN.md §42).
 */
export function ConfirmAction({
    children,
    ariaLabel,
    variant = 'ghost',
    size = 'sm',
    icon,
    title,
    description,
    confirmLabel,
    tone = 'danger',
    method,
    href,
    disabled = false,
}: ConfirmActionProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        router.visit(href, {
            method,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    };

    return (
        <>
            <Button variant={variant} size={size} icon={icon} aria-label={ariaLabel} disabled={disabled} onClick={() => setOpen(true)}>
                {children}
            </Button>
            <ConfirmDialog
                open={open}
                title={title}
                description={description}
                confirmLabel={confirmLabel}
                tone={tone}
                processing={processing}
                onConfirm={confirm}
                onCancel={() => setOpen(false)}
            />
        </>
    );
}
