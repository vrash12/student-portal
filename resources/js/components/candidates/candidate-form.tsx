import type { InertiaForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, PasswordInput, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { terms } from '@/lib/terminology';

export interface ClassOptionGroup {
    period: string;
    isActive: boolean;
    classes: Array<{ id: number; name: string }>;
}

export interface StatusOption {
    value: string;
    label: string;
}

export interface CandidateFormData {
    candidate_number: string;
    first_name: string;
    last_name: string;
    middle_name: string;
    suffix: string;
    training_group: string;
    company: string;
    platoon: string;
    profile_photo: File | null;
    remove_photo: boolean;
    class_batch_id: string;
    status: string;
    account_active: boolean;
    password: string;
    password_confirmation: string;
}

interface CandidateFormProps {
    form: InertiaForm<CandidateFormData>;
    mode: 'create' | 'edit';
    classOptions: ClassOptionGroup[];
    statusOptions?: StatusOption[];
    /** Company and platoon names already in use, suggested while typing. */
    companyOptions?: string[];
    platoonOptions?: string[];
    /** Current sign-in username, when editing. */
    currentUsername?: string;
    currentPhotoUrl?: string | null;
    submitLabel: string;
    cancelHref: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function CandidateForm({
    form,
    mode,
    classOptions,
    statusOptions = [],
    companyOptions = [],
    platoonOptions = [],
    currentUsername,
    currentPhotoUrl,
    submitLabel,
    cancelHref,
    onSubmit,
}: CandidateFormProps) {
    const classTerm = terms.classBatch.singular;
    const username = form.data.candidate_number.trim().toLowerCase();
    const usernameChanges = mode === 'edit' && currentUsername !== undefined && username !== '' && username !== currentUsername;
    const companyListId = useId();
    const platoonListId = useId();

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Basic Information">
                <FormField
                    label="Candidate Number"
                    required
                    error={form.errors.candidate_number}
                    hint="Also used as the candidate's sign-in username."
                >
                    <TextInput
                        name="candidate_number"
                        value={form.data.candidate_number}
                        onChange={(event) => form.setData('candidate_number', event.target.value)}
                        maxLength={30}
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        className="sm:max-w-xs"
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="First Name" required error={form.errors.first_name}>
                        <TextInput
                            name="first_name"
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                            maxLength={100}
                            autoComplete="off"
                        />
                    </FormField>
                    <FormField label="Middle Name" error={form.errors.middle_name} hint="Optional">
                        <TextInput name="middle_name" value={form.data.middle_name} onChange={(event) => form.setData('middle_name', event.target.value)} maxLength={100} autoComplete="off" />
                    </FormField>
                    <FormField label="Last Name" required error={form.errors.last_name}>
                        <TextInput
                            name="last_name"
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                            maxLength={100}
                            autoComplete="off"
                        />
                    </FormField>
                    <FormField label="Suffix" error={form.errors.suffix} hint="Optional, e.g. Jr. or III">
                        <TextInput name="suffix" value={form.data.suffix} onChange={(event) => form.setData('suffix', event.target.value)} maxLength={20} autoComplete="off" />
                    </FormField>
                </div>
                <FormField label="Profile Photo" error={form.errors.profile_photo} hint="Optional. JPEG, PNG or WebP, up to 2 MB and 4096 × 4096 pixels.">
                    <TextInput key={form.data.remove_photo ? 'removed' : 'photo'} type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" disabled={form.data.remove_photo} onChange={(event) => form.setData('profile_photo', event.target.files?.[0] ?? null)} />
                </FormField>
                {currentPhotoUrl && (
                    <div className="flex items-center gap-4">
                        <img src={currentPhotoUrl} alt="Current candidate profile" className="size-20 rounded-lg border border-line object-cover" />
                        <CheckboxField label="Remove current photo" checked={form.data.remove_photo} onChange={(event) => { form.setData('remove_photo', event.target.checked); if (event.target.checked) form.setData('profile_photo', null); }} error={form.errors.remove_photo} />
                    </div>
                )}
                {form.progress && <p role="status" className="text-sm text-ink-muted">Uploading: {form.progress.percentage}%</p>}
            </FormSection>

            <FormSection title="Academic Assignment" description="Enrolled subjects and assigned instructors follow the selected class. Grades and standing come from its finalized assessments.">
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label={classTerm} error={form.errors.class_batch_id}>
                        <SelectInput
                            name="class_batch_id"
                            value={form.data.class_batch_id}
                            onChange={(event) => form.setData('class_batch_id', event.target.value)}
                        >
                            <option value="">Not assigned</option>
                            {classOptions.map((group) => (
                                <optgroup key={group.period} label={`${group.period}${group.isActive ? ' (active)' : ''}`}>
                                    {group.classes.map((classBatch) => (
                                        <option key={classBatch.id} value={String(classBatch.id)}>
                                            {classBatch.name}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </SelectInput>
                    </FormField>

                    {mode === 'edit' && (
                        <FormField label="Status" required error={form.errors.status}>
                            <SelectInput name="status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                {statusOptions.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </SelectInput>
                        </FormField>
                    )}
                </div>
                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField label="Company" error={form.errors.company} hint={groupHint(companyOptions, 'Alpha Company')}>
                        <TextInput
                            name="company"
                            value={form.data.company}
                            onChange={(event) => form.setData('company', event.target.value)}
                            maxLength={50}
                            autoComplete="off"
                            list={companyOptions.length > 0 ? companyListId : undefined}
                        />
                    </FormField>
                    <FormField label="Platoon" error={form.errors.platoon} hint={groupHint(platoonOptions, '1st Platoon')}>
                        <TextInput
                            name="platoon"
                            value={form.data.platoon}
                            onChange={(event) => form.setData('platoon', event.target.value)}
                            maxLength={50}
                            autoComplete="off"
                            list={platoonOptions.length > 0 ? platoonListId : undefined}
                        />
                    </FormField>
                </div>
                <Suggestions id={companyListId} values={companyOptions} />
                <Suggestions id={platoonListId} values={platoonOptions} />
                <FormField label="Training Group / Section" error={form.errors.training_group} hint="Optional. Leave blank if your training program does not use this grouping.">
                    <TextInput name="training_group" value={form.data.training_group} onChange={(event) => form.setData('training_group', event.target.value)} maxLength={100} />
                </FormField>
            </FormSection>

            <FormSection
                title="Account Access"
                description={
                    mode === 'create'
                        ? 'The candidate signs in on the examination tablets with this account.'
                        : 'Leave the password blank to keep the current one. A new password signs the candidate out of active sessions.'
                }
            >
                <p className="text-sm text-ink-muted">
                    Username: <span className="font-medium text-ink">{username || '—'}</span>
                </p>
                {usernameChanges && (
                    <p className="text-sm text-warning-fg">
                        Saving changes the sign-in username from {currentUsername} to {username} and signs the candidate out.
                    </p>
                )}

                {mode === 'edit' && (
                    <CheckboxField
                        label="Account is active"
                        description="Deactivated candidates cannot sign in to the examination portal."
                        checked={form.data.account_active}
                        onChange={(event) => form.setData('account_active', event.target.checked)}
                        error={form.errors.account_active}
                    />
                )}

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label={mode === 'create' ? 'Initial Password' : 'New Password'}
                        required={mode === 'create'}
                        error={form.errors.password}
                        hint="At least 10 characters."
                    >
                        <PasswordInput
                            name="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            autoComplete="new-password"
                        />
                    </FormField>
                    <FormField label="Confirm Password" required={mode === 'create'} error={form.errors.password_confirmation}>
                        <PasswordInput
                            name="password_confirmation"
                            value={form.data.password_confirmation}
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            autoComplete="new-password"
                        />
                    </FormField>
                </div>
            </FormSection>

            <FormActions>
                <ButtonLink href={cancelHref} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}

function groupHint(options: string[], example: string): string {
    return options.length > 0 ? 'Optional. Names already in use are suggested as you type.' : `Optional, e.g. ${example}.`;
}

/** Typing suggestions for a text field; the field still accepts any name. */
function Suggestions({ id, values }: { id: string; values: string[] }) {
    if (values.length === 0) {
        return null;
    }

    return (
        <datalist id={id}>
            {values.map((value) => (
                <option key={value} value={value} />
            ))}
        </datalist>
    );
}
