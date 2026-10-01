import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { ChargeEntry } from '@/components/accounts/types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { FormField, TextArea } from '@/components/ui/form-field';
import { formatCalendarDate } from '@/lib/format';
import { routes } from '@/lib/routes';

interface VoidEntryDialogProps {
    /** The entry to void; null closes the dialog. */
    entry: ChargeEntry | null;
    /** Already formatted amount of the entry. */
    amountLabel: string;
    onClose: () => void;
}

/**
 * Voids a mistaken entry (UI_UX_DESIGN.md §41–42). Entries are never edited
 * or deleted: the voided entry stays listed, marked as voided, and
 * no longer counts toward the balance. A reason is always required.
 */
export function VoidEntryDialog({ entry, amountLabel, onClose }: VoidEntryDialogProps) {
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const form = useForm<{ reason: string }>({ reason: '' });
    const formId = 'void-account-entry-form';

    const close = () => {
        form.reset();
        form.clearErrors();
        setConnectionError(null);
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (entry === null) {
            return;
        }

        form.post(routes.accounts.entries.void(entry.id), {
            preserveScroll: true,
            onStart: () => setConnectionError(null),
            onSuccess: close,
            onNetworkError: () => {
                setConnectionError('The entry was not voided because the connection was interrupted. Nothing has changed. Check the connection and try again.');

                return false;
            },
        });
    };

    return (
        <Dialog
            open={entry !== null}
            title="Void this entry?"
            description={
                entry !== null && (
                    <>
                        {entry.type.label} of {amountLabel} on {formatCalendarDate(entry.postedOn)} ({entry.category}: {entry.description}). The entry stays on
                        the statement, marked as voided, and no longer counts toward the balance. Record the correct entry separately if needed.
                    </>
                )
            }
            busy={form.processing}
            onClose={close}
            footer={
                <>
                    <Button variant="secondary" onClick={close} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" form={formId} variant="danger" loading={form.processing}>
                        Void Entry
                    </Button>
                </>
            }
        >
            <form id={formId} onSubmit={submit} noValidate className="flex flex-col gap-4">
                {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}
                <FormField label="Reason for voiding" required error={form.errors.reason} hint="Kept with the entry and in the audit history. 5 to 255 characters.">
                    <TextArea
                        name="reason"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        maxLength={255}
                        rows={3}
                        placeholder="e.g. Recorded twice; duplicate of the entry of the same date"
                    />
                </FormField>
            </form>
        </Dialog>
    );
}
