import { Link, type InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, PasswordInput, SelectInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { Permission, usePermissions } from '@/lib/permissions';
import { routes } from '@/lib/routes';
import type { CampusOption } from '@/types';

export interface RoleOption {
    id: number;
    name: string;
    description: string | null;
    /** Teaching roles: the account must belong to one campus. */
    requiresCampus: boolean;
}

/** Where an account may be placed (App\Http\Controllers\Staff\UserController::campusChoices). */
export interface CampusChoices {
    campusOptions: CampusOption[];
    /** Whether "All campuses" can be chosen (only by accounts that see every campus). */
    canChooseEveryCampus: boolean;
}

export interface UserFormData {
    name: string;
    username: string;
    email: string;
    role_id: string;
    /** '' = every campus (administrator roles only). */
    campus_id: string;
    is_active: boolean;
    password: string;
    password_confirmation: string;
}

interface UserFormProps extends CampusChoices {
    form: InertiaForm<UserFormData>;
    roles: RoleOption[];
    mode: 'create' | 'edit';
    isOwnAccount?: boolean;
    /** Edit only: whether the campus can be changed here (institution-wide administrators, not on their own account). */
    canChangeCampus?: boolean;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function UserForm({
    form,
    roles,
    campusOptions,
    canChooseEveryCampus,
    mode,
    isOwnAccount = false,
    canChangeCampus = true,
    submitLabel,
    onSubmit,
}: UserFormProps) {
    const { can } = usePermissions();
    const roleOptions = roles.map((role) => ({ value: String(role.id), label: role.name, description: role.description }));
    const selectedRole = roles.find((role) => String(role.id) === form.data.role_id);
    const offersEveryCampus = canChooseEveryCampus && !selectedRole?.requiresCampus;
    const currentCampus = campusOptions.find((campus) => String(campus.id) === form.data.campus_id);
    // A campus administrator's accounts are always on their own campus: nothing to choose.
    const choosesCampus = canChangeCampus && (offersEveryCampus || campusOptions.length > 1 || form.data.campus_id === '');

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
            <FormSection title="Account Details" description="How the person is identified and signs in.">
                <FormField label="Full Name" required error={form.errors.name}>
                    <TextInput
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        autoComplete="off"
                        maxLength={150}
                    />
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Username"
                        required
                        error={form.errors.username}
                        hint="Lowercase letters, numbers, periods, hyphens, and underscores."
                    >
                        <TextInput
                            name="username"
                            value={form.data.username}
                            onChange={(event) => form.setData('username', event.target.value.toLowerCase())}
                            autoComplete="off"
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            maxLength={50}
                        />
                    </FormField>

                    <FormField label="Email" error={form.errors.email} hint="Optional.">
                        <TextInput
                            name="email"
                            type="email"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            autoComplete="off"
                            maxLength={255}
                        />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Access" description="The role determines what this account can see and do.">
                {mode === 'create' ? (
                    <RadioCards
                        legend="Role"
                        name="role_id"
                        required
                        options={roleOptions}
                        value={form.data.role_id}
                        onChange={(value) => form.setData('role_id', value)}
                        error={form.errors.role_id}
                    />
                ) : (
                    // The role is fixed once the account exists (owner decision, 2026-10-03).
                    <div>
                        <p className="text-sm font-medium text-ink">Role</p>
                        <p className="mt-1 font-semibold text-ink">{roles[0]?.name ?? '—'}</p>
                        {roles[0]?.description && <p className="text-sm text-ink-muted">{roles[0].description}</p>}
                        <p className="mt-2 text-sm text-ink-muted">The role is set when the account is created and cannot be changed.</p>
                        {form.errors.role_id && <p role="alert" className="mt-1 text-sm text-danger-fg">{form.errors.role_id}</p>}
                    </div>
                )}

                {choosesCampus ? (
                    <FormField
                        label="Campus"
                        required={selectedRole?.requiresCampus}
                        error={form.errors.campus_id}
                        hint={
                            selectedRole?.requiresCampus && campusOptions.length === 0 ? (
                                // Nothing to choose: every campus is switched off.
                                <>
                                    Instructors teach at one campus, and no campus is active.{' '}
                                    {can(Permission.ManageCampuses) ? (
                                        <Link href={routes.campuses.index()} className="text-primary-700 underline">
                                            Switch a campus on first.
                                        </Link>
                                    ) : (
                                        'Ask an administrator to switch a campus on first.'
                                    )}
                                </>
                            ) : selectedRole?.requiresCampus ? (
                                'Instructors teach only at their own campus.'
                            ) : canChooseEveryCampus ? (
                                'Accounts limited to a campus see only its classes, candidates and records. All campuses is for administrators of the whole institution.'
                            ) : undefined
                        }
                    >
                        <SelectInput name="campus_id" value={form.data.campus_id} onChange={(event) => form.setData('campus_id', event.target.value)}>
                            {offersEveryCampus ? <option value="">All campuses</option> : <option value="">Choose a campus</option>}
                            {campusOptions.map((campus) => (
                                <option key={campus.id} value={String(campus.id)}>
                                    {campus.isActive ? campus.name : `${campus.name} (inactive)`}
                                </option>
                            ))}
                        </SelectInput>
                    </FormField>
                ) : (
                    <div>
                        <p className="text-sm font-medium text-ink">Campus</p>
                        <p className="mt-1 text-ink">{currentCampus?.name ?? 'All campuses'}</p>
                        {mode === 'edit' && (
                            <p className="mt-1 text-sm text-ink-muted">
                                {isOwnAccount ? 'You cannot change the campus of your own account.' : 'Only an administrator of every campus can move an account to another campus.'}
                            </p>
                        )}
                        {form.errors.campus_id && <p role="alert" className="mt-1 text-sm text-danger-fg">{form.errors.campus_id}</p>}
                    </div>
                )}

                <CheckboxField
                    label="Account is active"
                    description="Active accounts can sign in. Deactivated accounts keep their history but cannot sign in."
                    checked={form.data.is_active}
                    onChange={(event) => form.setData('is_active', event.target.checked)}
                    error={form.errors.is_active}
                    disabled={isOwnAccount}
                />
            </FormSection>

            <FormSection
                title={mode === 'create' ? 'Initial Password' : 'Reset Password'}
                description={
                    mode === 'create'
                        ? 'Share the initial password with the user through a secure channel.'
                        : 'Leave blank to keep the current password. A new password signs the user out of active sessions.'
                }
            >
                {isOwnAccount ? (
                    <p className="text-sm text-ink-muted">
                        To change your own password, use{' '}
                        <Link href={routes.account.password()} className="font-medium text-primary-700 underline">
                            Change Password
                        </Link>
                        .
                    </p>
                ) : (
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField
                            label={mode === 'create' ? 'Password' : 'New Password'}
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
                )}
            </FormSection>

            <FormActions>
                <ButtonLink href={routes.users.index()} variant="secondary">
                    Cancel
                </ButtonLink>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </FormActions>
        </form>
    );
}
