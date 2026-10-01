import { useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { formatPoints } from '@/components/conduct/conduct-totals';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { StatusBadge } from '@/components/ui/status-badge';
import { routes } from '@/lib/routes';
import type { ConductKindGroup, ConductTypeOption } from '@/types/conduct';

interface RecordConductFormProps {
    candidateId: number;
    /** Active types, merits first. */
    types: ConductTypeOption[];
    kinds: ConductKindGroup[];
    /** Today in the institution's timezone (Y-m-d); later dates are refused. */
    today: string;
}

interface ConductEntryFormData {
    conduct_type_id: string;
    points: string;
    occurred_on: string;
    reason: string;
}

/**
 * Records one merit or demerit. The type decides the kind (the server copies
 * it); choosing a type fills in its usual points, which may be adjusted.
 */
export function RecordConductForm({ candidateId, types, kinds, today }: RecordConductFormProps) {
    const [connectionError, setConnectionError] = useState<string | null>(null);
    // Refusals that are not about one field, e.g. the candidate was withdrawn meanwhile.
    const { errors } = usePage().props;
    const form = useForm<ConductEntryFormData>({
        conduct_type_id: '',
        points: '',
        occurred_on: today,
        reason: '',
    });

    const selectedType = types.find((type) => String(type.id) === form.data.conduct_type_id) ?? null;
    const selectedKind = selectedType === null ? null : (kinds.find((kind) => kind.value === selectedType.kind) ?? null);

    const chooseType = (typeId: string) => {
        const type = types.find((option) => String(option.id) === typeId);
        form.setData((current) => ({
            ...current,
            conduct_type_id: typeId,
            points: type === undefined ? current.points : String(type.defaultPoints),
        }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.conduct.entries.store(candidateId), {
            preserveScroll: true,
            onStart: () => setConnectionError(null),
            // Keep the date for the next entry; clear the rest.
            onSuccess: () => form.reset('conduct_type_id', 'points', 'reason'),
            onNetworkError: () => {
                setConnectionError('The entry was not saved because the connection was interrupted. Check the connection and try again.');

                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}
            {errors.candidate !== undefined && <Alert tone="danger">{errors.candidate}</Alert>}

            <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Type"
                    required
                    error={form.errors.conduct_type_id}
                    hint={
                        selectedType !== null && selectedKind !== null ? (
                            <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                                <StatusBadge tone={selectedKind.tone}>{selectedKind.label}</StatusBadge>
                                <span>
                                    Usually {formatPoints(selectedType.defaultPoints)}
                                    {selectedType.description !== null && `. ${selectedType.description}`}
                                </span>
                            </span>
                        ) : (
                            'The type decides whether the entry is a merit or a demerit.'
                        )
                    }
                >
                    <SelectInput name="conduct_type_id" value={form.data.conduct_type_id} onChange={(event) => chooseType(event.target.value)}>
                        <option value="">Choose a type</option>
                        {kinds.map((kind) => {
                            const options = types.filter((type) => type.kind === kind.value);

                            return (
                                options.length > 0 && (
                                    <optgroup key={kind.value} label={kind.pluralLabel}>
                                        {options.map((type) => (
                                            <option key={type.id} value={String(type.id)}>
                                                {type.name} ({formatPoints(type.defaultPoints)})
                                            </option>
                                        ))}
                                    </optgroup>
                                )
                            );
                        })}
                    </SelectInput>
                </FormField>
                <FormField label="Date" required error={form.errors.occurred_on} hint="When it happened. Cannot be later than today.">
                    <TextInput
                        type="date"
                        name="occurred_on"
                        value={form.data.occurred_on}
                        max={today}
                        onChange={(event) => form.setData('occurred_on', event.target.value)}
                    />
                </FormField>
            </div>

            <div className="grid gap-5 sm:grid-cols-3">
                <FormField label="Points" required error={form.errors.points} hint="Whole number from 1 to 100.">
                    <TextInput
                        name="points"
                        value={form.data.points}
                        onChange={(event) => form.setData('points', event.target.value)}
                        inputMode="numeric"
                        autoComplete="off"
                        className="tabular-nums"
                    />
                </FormField>
                <FormField label="Reason" required error={form.errors.reason} className="sm:col-span-2" hint="What happened. Up to 255 characters.">
                    <TextInput
                        name="reason"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                        placeholder="e.g. Led the platoon during the field exercise"
                    />
                </FormField>
            </div>

            <div className="flex justify-end">
                <Button type="submit" loading={form.processing} icon={<Plus className="size-4" aria-hidden="true" />}>
                    {selectedKind === null ? 'Record Entry' : `Record ${selectedKind.label}`}
                </Button>
            </div>
        </form>
    );
}
