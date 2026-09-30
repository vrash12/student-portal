import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Button, type ButtonSize, type ButtonVariant } from '@/components/ui/button';

interface PostActionButtonProps {
    href: string;
    children: ReactNode;
    icon?: ReactNode;
    variant?: ButtonVariant;
    size?: ButtonSize;
    /**
     * Set to false when the response may show the same page for another
     * record (e.g. Duplicate from the edit page), so no form state carries over.
     */
    preserveState?: boolean;
}

/**
 * A button for an action that needs no confirmation (for example Duplicate
 * or Activate). It sends a POST request and stays disabled while the request
 * runs, so the action cannot be sent twice.
 */
export function PostActionButton({ href, children, icon, variant = 'secondary', size = 'md', preserveState = true }: PostActionButtonProps) {
    const [processing, setProcessing] = useState(false);

    const send = () => {
        if (processing) {
            return;
        }

        router.post(
            href,
            {},
            {
                preserveScroll: preserveState,
                preserveState,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Button variant={variant} size={size} icon={icon} loading={processing} onClick={send}>
            {children}
        </Button>
    );
}
