import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/cn';

/**
 * Configured organization logo, or a neutral placeholder mark until approved
 * branding exists. Never replace this with fabricated insignia.
 */
export function BrandMark({ className, round = false }: { className?: string; /** Circular crop, for round emblems. */ round?: boolean }) {
    const { app } = usePage().props;

    if (app.logoUrl) {
        return (
            <img
                src={app.logoUrl}
                alt={`${app.organizationName} logo`}
                className={cn('size-9 shrink-0', round ? 'rounded-full object-cover' : 'rounded-md object-contain', className)}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-md bg-primary-600 text-white',
                className,
            )}
        >
            <svg viewBox="0 0 32 32" className="size-2/3" fill="currentColor">
                <rect x="6" y="7" width="20" height="3" rx="1.5" />
                <rect x="6" y="14.5" width="20" height="3" rx="1.5" opacity="0.85" />
                <rect x="6" y="22" width="12" height="3" rx="1.5" opacity="0.7" />
            </svg>
        </span>
    );
}
