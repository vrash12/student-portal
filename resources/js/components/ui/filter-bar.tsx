import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';

interface FilterBarProps {
    children: ReactNode;
    onReset: () => void;
    canReset: boolean;
}

/**
 * Filters above a list, with an easy way to reset them (UI_UX_DESIGN.md §52).
 */
export function FilterBar({ children, onReset, canReset }: FilterBarProps) {
    return (
        <div className="flex flex-col gap-4 border-b border-line p-4 sm:flex-row sm:flex-wrap sm:items-end">
            {children}
            <Button variant="ghost" onClick={onReset} disabled={!canReset} className="sm:ml-auto">
                Reset Filters
            </Button>
        </div>
    );
}

interface SearchFieldProps {
    label?: string;
    /** Describe the scope, e.g. "Search candidates by number or name…" (§51). */
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
}

export function SearchField({ label = 'Search', placeholder, value, onChange }: SearchFieldProps) {
    return (
        <FormField label={label} className="min-w-0 sm:min-w-64 sm:flex-1">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle"
                    aria-hidden="true"
                />
                <TextInput
                    type="search"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder={placeholder}
                    className="pl-9"
                    maxLength={100}
                />
            </div>
        </FormField>
    );
}
