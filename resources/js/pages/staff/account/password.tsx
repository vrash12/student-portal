import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FormField, PasswordInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { routes } from '@/lib/routes';

export default function AccountPassword() {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.account.password(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Change Password" />

            <div className="mx-auto max-w-2xl">
                <PageHeader
                    title="Change Password"
                    description="Your other signed-in sessions are signed out after the password changes."
                />

                <Panel>
                    <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                        <FormField label="Current Password" required error={form.errors.current_password}>
                            <PasswordInput
                                name="current_password"
                                value={form.data.current_password}
                                onChange={(event) => form.setData('current_password', event.target.value)}
                                autoComplete="current-password"
                            />
                        </FormField>

                        <FormField label="New Password" required error={form.errors.password} hint="At least 10 characters.">
                            <PasswordInput
                                name="password"
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                autoComplete="new-password"
                            />
                        </FormField>

                        <FormField label="Confirm New Password" required error={form.errors.password_confirmation}>
                            <PasswordInput
                                name="password_confirmation"
                                value={form.data.password_confirmation}
                                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                autoComplete="new-password"
                            />
                        </FormField>

                        <div className="flex justify-end">
                            <Button type="submit" loading={form.processing}>
                                Update Password
                            </Button>
                        </div>
                    </form>
                </Panel>
            </div>
        </>
    );
}
