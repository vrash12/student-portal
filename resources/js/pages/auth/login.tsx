import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FormField, PasswordInput, TextInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';

export default function Login() {
    const form = useForm({
        username: '',
        password: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.login(), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <>
            <Head title="Sign In" />

            <h1 className="text-xl font-semibold text-ink">Sign in</h1>
            <p className="mt-1 text-sm text-ink-muted">Use the username and password issued by your administrator.</p>

            <form onSubmit={submit} noValidate className="mt-6 flex flex-col gap-5">
                <FormField label="Username" error={form.errors.username}>
                    <TextInput
                        name="username"
                        value={form.data.username}
                        onChange={(event) => form.setData('username', event.target.value)}
                        autoComplete="username"
                        autoCapitalize="none"
                        autoCorrect="off"
                        spellCheck={false}
                        autoFocus
                    />
                </FormField>

                <FormField label="Password" error={form.errors.password}>
                    <PasswordInput
                        name="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        autoComplete="current-password"
                    />
                </FormField>

                <Button type="submit" size="lg" loading={form.processing} className="w-full">
                    Sign In
                </Button>
            </form>

            <p className="mt-6 text-sm text-ink-muted">Forgot your password? Contact your system administrator.</p>
        </>
    );
}
