import { Link, type InertiaForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { CheckboxField, FormField, PasswordInput, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { RadioCards } from '@/components/ui/radio-cards';
import { routes } from '@/lib/routes';

export interface RoleOption {
    id: number;
    name: string;
    description: string | null;
}

export interface UserFormData {
    name: string;
    username: string;
    email: string;
    role_id: string;
    is_active: boolean;
    password: string;
    password_confirmation: string;
}

interface UserFormProps {
    form: InertiaForm<UserFormData>;
    roles: RoleOption[];
    mode: 'create' | 'edit';
    isOwnAccount?: boolean;
    submitLabel: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export function UserForm({ form, roles, mode, isOwnAccount = false, submitLabel, onSubmit }: UserFormProps) {
    const roleOptions = roles.map((role) => ({ value: String(role.id), label: role.name, description: role.description }));

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
                <RadioCards
                    legend="Role"
                    name="role_id"
                    required
                    options={roleOptions}
                    value={form.data.role_id}
                    onChange={(value) => form.setData('role_id', value)}
                    error={form.errors.role_id}
                    disabled={isOwnAccount}
                />
                {isOwnAccount && <p className="text-sm text-ink-muted">You cannot change the role of your own account.</p>}
                {mode === 'edit' && !isOwnAccount && (
                    <p className="text-sm text-ink-muted">Changing the role signs the user out of their active sessions.</p>
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
