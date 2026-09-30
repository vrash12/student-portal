import { Lock } from 'lucide-react';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/cn';

/** Active or Inactive, shown with an icon and text (never color alone). */
export function QuestionStatusBadge({ isActive }: { isActive: boolean }) {
    return isActive ? <StatusBadge tone="success">Active</StatusBadge> : <StatusBadge tone="neutral">Inactive</StatusBadge>;
}

/**
 * Marks a question that is part of a published examination: its type, text,
 * and answers can no longer change.
 */
export function LockedIndicator({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md border border-info-border bg-info-bg px-2 py-0.5 text-xs text-info-fg',
                className,
            )}
        >
            <Lock className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="font-medium">Locked</span>
            <span aria-hidden="true">·</span>
            <span>Used in a published examination</span>
        </span>
    );
}
