import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField, SelectInput, TextInput } from '@/components/ui/form-field';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';

export interface CategoryOption {
    id: number;
    name: string;
    /** The side entries of this category usually take. */
    entryType: string;
}

interface RecordEntryFormProps {
    candidateId: number;
    categories: CategoryOption[];
    entryTypes: Array<{ value: string; label: string; description: string }>;
    /** Today in the institution's timezone (Y-m-d). */
    today: string;
}

interface EntryFormData {
    entry_type: string;
    account_category_id: string;
    amount: string;
    posted_on: string;
    description: string;
    reference: string;
}

/**
 * Records one charge or credit. Choosing a category selects its usual side;
 * the side can still be changed (e.g. a refund of a uniform charge).
 */
export function RecordEntryForm({ candidateId, categories, entryTypes, today }: RecordEntryFormProps) {
    const [connectionError, setConnectionError] = useState<string | null>(null);
    const form = useForm<EntryFormData>({
        entry_type: 'charge',
        account_category_id: '',
        amount: '',
        posted_on: today,
        description: '',
        reference: '',
    });

    const chooseCategory = (categoryId: string) => {
        const category = categories.find((option) => String(option.id) === categoryId);
        form.setData((current) => ({ ...current, account_category_id: categoryId, entry_type: category?.entryType ?? current.entry_type }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.accounts.entries.store(candidateId), {
            preserveScroll: true,
            onStart: () => setConnectionError(null),
            // Keep the date and side for the next entry; clear the rest.
            onSuccess: () => form.reset('account_category_id', 'amount', 'description', 'reference'),
            onNetworkError: () => {
                setConnectionError('The entry was not saved because the connection was interrupted. Check the connection and try again.');

                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-5">
            {connectionError !== null && <Alert tone="danger">{connectionError}</Alert>}

            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="Category" required error={form.errors.account_category_id}>
                    <SelectInput name="account_category_id" value={form.data.account_category_id} onChange={(event) => chooseCategory(event.target.value)}>
                        <option value="">Choose a category</option>
                        {categories.map((category) => (
                            <option key={category.id} value={String(category.id)}>
                                {category.name}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
                <FormField label="Date" required error={form.errors.posted_on}>
                    <TextInput type="date" name="posted_on" value={form.data.posted_on} onChange={(event) => form.setData('posted_on', event.target.value)} />
                </FormField>
            </div>

            <RadioCards
                legend="Type"
                name="entry_type"
                options={entryTypes}
                value={form.data.entry_type}
                onChange={(value) => form.setData('entry_type', value)}
                error={form.errors.entry_type}
                required
            />

            <div className="grid gap-5 sm:grid-cols-3">
                <FormField label="Amount" required error={form.errors.amount} hint="e.g. 1250 or 1,250.50">
                    <TextInput
                        name="amount"
                        value={form.data.amount}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        inputMode="decimal"
                        autoComplete="off"
                        className="tabular-nums"
                    />
                </FormField>
                <FormField label="Description" required error={form.errors.description} className="sm:col-span-2">
                    <TextInput
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        maxLength={255}
                        autoComplete="off"
                        placeholder="e.g. Uniform set, September meal allowance"
                    />
                </FormField>
            </div>

            <FormField label="Reference" error={form.errors.reference} hint="Optional. Receipt, voucher or deposit slip number." className="sm:w-1/2">
                <TextInput name="reference" value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} maxLength={100} autoComplete="off" />
            </FormField>

            <div className="flex justify-end">
                <Button type="submit" loading={form.processing} icon={<Plus className="size-4" aria-hidden="true" />}>
                    Record Entry
                </Button>
            </div>
        </form>
    );
}
