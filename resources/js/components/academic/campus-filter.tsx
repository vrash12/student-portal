import { FormField, SelectInput } from '@/components/ui/form-field';
import type { CampusOption, CampusSummary } from '@/types';

interface CampusFilterProps {
    /** Campuses the viewer may filter by; empty for accounts limited to one campus. */
    options: CampusOption[];
    value: string;
    onChange: (value: string) => void;
    className?: string;
}

/**
 * The Campus filter of lists, dashboards and reports. Shown only to accounts
 * that see every campus, and only when there is more than one campus to
 * choose from. The server narrows the data; this only sends the choice.
 */
export function CampusFilter({ options, value, onChange, className = 'sm:w-48' }: CampusFilterProps) {
    if (options.length < 2) {
        return null;
    }

    return (
        <FormField label="Campus" className={className}>
            <SelectInput value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">All campuses</option>
                {options.map((campus) => (
                    <option key={campus.id} value={String(campus.id)}>
                        {campus.isActive ? campus.name : `${campus.name} (inactive)`}
                    </option>
                ))}
            </SelectInput>
        </FormField>
    );
}

/** Whether lists should show a Campus column: the viewer can see more than one campus. */
export function showsCampuses(options: CampusOption[]): boolean {
    return options.length > 1;
}

/** A campus as plain text in tables and details. */
export function campusName(campus: CampusSummary | null | undefined, fallback = 'No campus'): string {
    return campus?.name ?? fallback;
}
