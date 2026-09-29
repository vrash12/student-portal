import { Link, type InertiaLinkProps } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';
export type ButtonSize = 'sm' | 'md' | 'lg';

const baseClasses =
    'inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60';

const variantClasses: Record<ButtonVariant, string> = {
    primary: 'bg-primary-600 text-white hover:bg-primary-700 active:bg-primary-800',
    secondary: 'border border-line-strong bg-surface text-ink hover:bg-surface-muted active:bg-neutral-bg',
    danger: 'bg-danger-600 text-white hover:bg-danger-700',
    ghost: 'text-ink-muted hover:bg-neutral-bg hover:text-ink',
};

// Touch devices get at least 44px targets (UI_UX_DESIGN.md §22).
const sizeClasses: Record<ButtonSize, string> = {
    sm: 'h-8 px-3 text-sm pointer-coarse:h-11',
    md: 'h-10 px-4 text-sm pointer-coarse:h-11',
    lg: 'h-12 px-5 text-base',
};

export function buttonClasses(variant: ButtonVariant = 'primary', size: ButtonSize = 'md', className?: string): string {
    return cn(baseClasses, variantClasses[variant], sizeClasses[size], className);
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Shows a spinner and disables the button to prevent double submission. */
    loading?: boolean;
    icon?: ReactNode;
}

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    icon,
    className,
    children,
    disabled,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            type={type}
            className={buttonClasses(variant, size, className)}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            {...props}
        >
            {loading ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : icon}
            {children}
        </button>
    );
}

// `size` is omitted because the HTML attribute of that name is numeric.
type ButtonLinkProps = Omit<InertiaLinkProps, 'size'> & {
    variant?: ButtonVariant;
    size?: ButtonSize;
    icon?: ReactNode;
};

export function ButtonLink({ variant = 'secondary', size = 'md', icon, className, children, ...props }: ButtonLinkProps) {
    return (
        <Link className={buttonClasses(variant, size, className)} {...props}>
            {icon}
            {children}
        </Link>
    );
}
