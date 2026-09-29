import { CircleAlert } from 'lucide-react';
import { useId } from 'react';
import { cn } from '@/lib/cn';

export interface RadioCardOption {
    value: string;
    label: string;
    description?: string | null;
}

interface RadioCardsProps {
    legend: string;
    name: string;
    options: RadioCardOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    disabled?: boolean;
}

/**
 * Radio group where the whole card is the tap target (UI_UX_DESIGN.md §23).
 * The selected state is shown by the radio mark, border, and background.
 */
export function RadioCards({ legend, name, options, value, onChange, error, required = false, disabled = false }: RadioCardsProps) {
    const errorId = useId();

    return (
        <fieldset aria-describedby={error ? errorId : undefined} disabled={disabled}>
            <legend className="mb-1.5 text-sm font-medium text-ink">
                {legend}
                {required && (
                    <span className="text-danger-fg" aria-hidden="true">
                        {' '}
                        *
                    </span>
                )}
            </legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const checked = option.value === value;

                    return (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600',
                                checked ? 'border-primary-600 bg-primary-50' : 'border-line-strong bg-surface hover:bg-surface-muted',
                                disabled && 'cursor-not-allowed opacity-60',
                            )}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={option.value}
                                checked={checked}
                                onChange={() => onChange(option.value)}
                                className="mt-0.5 size-4 shrink-0 accent-primary-600 focus-visible:outline-none"
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-medium text-ink">{option.label}</span>
                                {option.description && (
                                    <span className="mt-0.5 block text-sm text-ink-muted">{option.description}</span>
                                )}
                            </span>
                        </label>
                    );
                })}
            </div>
            {error && (
                <p id={errorId} className="mt-1.5 flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </fieldset>
    );
}
