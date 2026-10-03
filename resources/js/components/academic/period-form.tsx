import type { InertiaForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';

export interface PeriodFormData {
    name: string;
    starts_on: string;
    ends_on: string;
}

interface PeriodFormProps {
    form: InertiaForm<PeriodFormData>;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    /** New periods: name the period after its years ("2026-2027") until the name is typed. */
    suggestName?: boolean;
}

/** One day short of a year after the start (Y-m-d), the last end date the server accepts. */
export function lastAllowedEnd(startsOn: string): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(startsOn);
    if (match === null) {
        return null;
    }
    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
    // Feb 29 has no match the next year: Feb 28 is the anniversary, like the server's addYearNoOverflow.
    const anniversary = new Date(Date.UTC(year + 1, month - 1, month === 2 && day === 29 ? 28 : day));
    anniversary.setUTCDate(anniversary.getUTCDate() - 1);

    return anniversary.toISOString().slice(0, 10);
}

/** "2026-2027", or "2027" within one calendar year (as AcademicPeriod::yearLabel). */
function yearLabel(startsOn: string, endsOn: string): string {
    const start = startsOn.slice(0, 4);
    const end = endsOn.slice(0, 4);

    return start === end ? start : `${start}-${end}`;
}

/**
 * An academic period is one year of the course at most (owner request,
 * 2026-10-03); its training phases lie inside it. The server enforces the
 * limit; the form only shows it.
 */
export function PeriodForm({ form, submitLabel, onSubmit, suggestName = false }: PeriodFormProps) {
    const [nameTyped, setNameTyped] = useState(!suggestName);
    const latestEnd = lastAllowedEnd(form.data.starts_on);

    const setDates = (field: 'starts_on' | 'ends_on', value: string) => {
        const dates = { starts_on: form.data.starts_on, ends_on: form.data.ends_on, [field]: value };
        form.setData((current) => ({
            ...current,
            [field]: value,
            ...(!nameTyped && /^\d{4}-\d{2}-\d{2}$/.test(dates.starts_on) && /^\d{4}-\d{2}-\d{2}$/.test(dates.ends_on)
                ? { name: yearLabel(dates.starts_on, dates.ends_on) }
                : {}),
        }));
    };

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Academic Year" description="One year of the course, for example 2026-2027. It lasts at most one year.">
                <FormField label="Name" required error={form.errors.name} hint={suggestName && !nameTyped ? 'Filled in from the dates. Type to use another name.' : undefined}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => {
                            setNameTyped(true);
                            form.setData('name', event.target.value);
                        }}
                        maxLength={100}
                        autoComplete="off"
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Start Date" required error={form.errors.starts_on}>
                        <TextInput type="date" name="starts_on" value={form.data.starts_on} onChange={(event) => setDates('starts_on', event.target.value)} />
                    </FormField>
                    <FormField
                        label="End Date"
                        required
                        error={form.errors.ends_on}
                        hint={latestEnd === null ? 'At most one year after the start date.' : `On or before ${formatCalendarDate(latestEnd)} (one year).`}
                    >
                        <TextInput
                            type="date"
                            name="ends_on"
                            value={form.data.ends_on}
                            min={form.data.starts_on || undefined}
                            max={latestEnd ?? undefined}
                            onChange={(event) => setDates('ends_on', event.target.value)}
                        />
                    </FormField>
                </div>
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.academicPeriods.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
