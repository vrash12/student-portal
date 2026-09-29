import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface EmptyStateProps {
    icon: LucideIcon;
    title: string;
    description: ReactNode;
    action?: ReactNode;
    headingLevel?: 'h2' | 'h3';
}

/**
 * Intentional empty screen: what is missing and what to do next (§47).
 */
export function EmptyState({ icon: Icon, title, description, action, headingLevel: Heading = 'h2' }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center px-6 py-12 text-center">
            <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-neutral-bg text-ink-muted">
                <Icon className="size-6" aria-hidden="true" />
            </div>
            <Heading className="text-base font-semibold text-ink">{title}</Heading>
            <p className="mt-1 max-w-md text-sm text-ink-muted">{description}</p>
            {action && <div className="mt-6">{action}</div>}
        </div>
    );
}
