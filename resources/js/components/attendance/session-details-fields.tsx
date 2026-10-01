import { FormField, TextArea, TextInput } from '@/components/ui/form-field';

/** Form fields of a training session, as the server expects them. */
export interface SessionDetailsData {
    held_on: string;
    title: string;
    hours: string;
    notes: string;
}

interface SessionDetailsFieldsProps {
    data: SessionDetailsData;
    errors: Partial<Record<keyof SessionDetailsData, string>>;
    /** Today in the institution's timezone (Y-m-d): sessions cannot be dated later. */
    today: string;
    onChange: (field: keyof SessionDetailsData, value: string) => void;
}

/** Date, title, hours and notes of a session (create and edit). */
export function SessionDetailsFields({ data, errors, today, onChange }: SessionDetailsFieldsProps) {
    return (
        <>
            <FormField label="Title" required error={errors.title} hint="e.g. Morning Formation, Field Training 1.">
                <TextInput name="title" value={data.title} onChange={(event) => onChange('title', event.target.value)} maxLength={150} autoComplete="off" />
            </FormField>
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="Session date" required error={errors.held_on} hint="The day the session was held; not a future date.">
                    <TextInput type="date" name="held_on" value={data.held_on} max={today} onChange={(event) => onChange('held_on', event.target.value)} />
                </FormField>
                <FormField label="Hours" required error={errors.hours} hint="Length of the session, such as 1.5. Attended hours add up from this.">
                    <TextInput
                        name="hours"
                        value={data.hours}
                        onChange={(event) => onChange('hours', event.target.value)}
                        inputMode="decimal"
                        autoComplete="off"
                        className="tabular-nums"
                    />
                </FormField>
            </div>
            <FormField label="Notes" error={errors.notes} hint="Optional. Location, instructor on duty, or conditions. Up to 500 characters.">
                <TextArea name="notes" value={data.notes} onChange={(event) => onChange('notes', event.target.value)} maxLength={500} rows={2} />
            </FormField>
        </>
    );
}
