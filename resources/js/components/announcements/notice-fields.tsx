import { CheckboxField, FormField, TextArea, TextInput } from '@/components/ui/form-field';

/** Form fields of a notice, as the server expects them. Dates in the institution's timezone (datetime-local). */
export interface NoticeFieldsData {
    title: string;
    body: string;
    is_important: boolean;
    publishes_at: string;
    expires_at: string;
}

interface NoticeFieldsProps {
    data: NoticeFieldsData;
    errors: Partial<Record<keyof NoticeFieldsData, string>>;
    /** When posting, a blank start means "now"; when changing, the start is required. */
    creating: boolean;
    onChange: <K extends keyof NoticeFieldsData>(field: K, value: NoticeFieldsData[K]) => void;
}

const BODY_LIMIT = 5000;

/** Title, message, importance and showing dates of a notice (post and edit). */
export function NoticeFields({ data, errors, creating, onChange }: NoticeFieldsProps) {
    return (
        <>
            <FormField label="Title" required error={errors.title} hint="e.g. Examination moved to Friday, Report for medical check-up.">
                <TextInput name="title" value={data.title} onChange={(event) => onChange('title', event.target.value)} maxLength={150} autoComplete="off" />
            </FormField>
            <FormField label="Message" required error={errors.body} hint={`What candidates need to know and do. ${data.body.length.toLocaleString()} of ${BODY_LIMIT.toLocaleString()} characters.`}>
                <TextArea name="body" value={data.body} onChange={(event) => onChange('body', event.target.value)} maxLength={BODY_LIMIT} rows={6} />
            </FormField>
            <CheckboxField
                name="is_important"
                label="Important"
                description="Shown first and highlighted on the candidates' home page."
                checked={data.is_important}
                onChange={(event) => onChange('is_important', event.target.checked)}
                error={errors.is_important}
            />
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Show from"
                    required={!creating}
                    error={errors.publishes_at}
                    hint={creating ? 'Leave blank to show it now, or choose a later date and time.' : 'When candidates start seeing it.'}
                >
                    <TextInput type="datetime-local" name="publishes_at" value={data.publishes_at} onChange={(event) => onChange('publishes_at', event.target.value)} />
                </FormField>
                <FormField label="Show until" error={errors.expires_at} hint="Optional. After this, candidates no longer see it.">
                    <TextInput type="datetime-local" name="expires_at" value={data.expires_at} onChange={(event) => onChange('expires_at', event.target.value)} />
                </FormField>
            </div>
        </>
    );
}
