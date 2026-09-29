import { ChevronDown, CircleAlert, Eye, EyeOff } from 'lucide-react';
import {
    createContext,
    useContext,
    useId,
    useState,
    type InputHTMLAttributes,
    type ReactNode,
    type SelectHTMLAttributes,
} from 'react';
import { cn } from '@/lib/cn';

interface FieldContextValue {
    id: string;
    describedBy: string | undefined;
    invalid: boolean;
    required: boolean;
}

const FieldContext = createContext<FieldContextValue | null>(null);

function useFormField(): FieldContextValue | null {
    return useContext(FieldContext);
}

interface FormFieldProps {
    label: string;
    children: ReactNode;
    hint?: ReactNode;
    error?: string;
    required?: boolean;
    className?: string;
}

/**
 * Visible label, optional hint, and field-level error (UI_UX_DESIGN.md §38–40).
 * Controls placed inside read their id and ARIA attributes from context.
 */
export function FormField({ label, children, hint, error, required = false, className }: FormFieldProps) {
    const id = useId();
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <FieldContext value={{ id, describedBy, invalid: Boolean(error), required }}>
            <div className={cn('flex flex-col gap-1.5', className)}>
                <label htmlFor={id} className="text-sm font-medium text-ink">
                    {label}
                    {required && (
                        <span className="text-danger-fg" aria-hidden="true">
                            {' '}
                            *
                        </span>
                    )}
                </label>
                {children}
                {hint && (
                    <p id={hintId} className="text-sm text-ink-subtle">
                        {hint}
                    </p>
                )}
                {error && (
                    <p id={errorId} className="flex items-start gap-1.5 text-sm text-danger-fg">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{error}</span>
                    </p>
                )}
            </div>
        </FieldContext>
    );
}

// Touch devices use 16px text so tablets do not zoom into focused fields.
const controlClasses =
    'block h-10 w-full rounded-md border bg-surface px-3 text-sm text-ink shadow-xs transition-colors placeholder:text-ink-subtle focus-visible:border-primary-600 focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-subtle pointer-coarse:h-11 pointer-coarse:text-base';

function stateClasses(invalid: boolean): string {
    return invalid ? 'border-danger-fg' : 'border-line-strong';
}

function fieldAttributes(field: FieldContextValue | null) {
    return {
        id: field?.id,
        'aria-describedby': field?.describedBy,
        'aria-invalid': field?.invalid || undefined,
        'aria-required': field?.required || undefined,
    };
}

export function TextInput({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    const field = useFormField();

    return (
        <input
            {...fieldAttributes(field)}
            className={cn(controlClasses, stateClasses(Boolean(field?.invalid)), className)}
            {...props}
        />
    );
}

export function PasswordInput({ className, ...props }: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
    const field = useFormField();
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <input
                {...fieldAttributes(field)}
                type={visible ? 'text' : 'password'}
                className={cn(controlClasses, stateClasses(Boolean(field?.invalid)), 'pr-12', className)}
                {...props}
            />
            <button
                type="button"
                onClick={() => setVisible((current) => !current)}
                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-md text-ink-muted hover:text-ink"
                aria-label={visible ? 'Hide password' : 'Show password'}
                aria-pressed={visible}
            >
                {visible ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
            </button>
        </div>
    );
}

export function SelectInput({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
    const field = useFormField();

    return (
        <div className="relative">
            <select
                {...fieldAttributes(field)}
                className={cn(controlClasses, stateClasses(Boolean(field?.invalid)), 'appearance-none pr-10', className)}
                {...props}
            >
                {children}
            </select>
            <ChevronDown
                className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
                aria-hidden="true"
            />
        </div>
    );
}

interface CheckboxFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
    description?: string;
    error?: string;
}

export function CheckboxField({ label, description, error, className, ...props }: CheckboxFieldProps) {
    const id = useId();
    const descriptionId = description ? `${id}-description` : undefined;
    const errorId = error ? `${id}-error` : undefined;

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <div className="flex items-start gap-3">
                <input
                    id={id}
                    type="checkbox"
                    className="mt-0.5 size-4 shrink-0 accent-primary-600 pointer-coarse:size-5"
                    aria-describedby={[descriptionId, errorId].filter(Boolean).join(' ') || undefined}
                    aria-invalid={Boolean(error) || undefined}
                    {...props}
                />
                <div className="min-w-0">
                    <label htmlFor={id} className="text-sm font-medium text-ink">
                        {label}
                    </label>
                    {description && (
                        <p id={descriptionId} className="text-sm text-ink-muted">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            {error && (
                <p id={errorId} className="flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
}
