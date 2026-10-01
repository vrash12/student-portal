import { Head, useForm, usePage } from '@inertiajs/react';
import { LockKeyhole, TriangleAlert } from 'lucide-react';
import { useState, type FormEvent, type KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FormField, PasswordInput, TextInput } from '@/components/ui/form-field';
import { routes } from '@/lib/routes';

export default function Login() {
    const { poweredBy } = usePage().props.app;
    const [capsLock, setCapsLock] = useState(false);
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

    const watchCapsLock = (event: KeyboardEvent<HTMLInputElement>) => setCapsLock(event.getModifierState('CapsLock'));

    return (
        <>
            <Head title="Sign In" />

            <div className="mb-8">
                <span className="mb-4 flex size-11 items-center justify-center rounded-xl bg-primary-50 text-primary-700" aria-hidden="true">
                    <LockKeyhole className="size-5" />
                </span>
                <h1 className="text-2xl font-bold tracking-tight text-ink">Sign in</h1>
                <p className="mt-2 text-sm leading-relaxed text-ink-muted">Use the username and password issued by your administrator. Candidates sign in with their candidate number.</p>
            </div>

            <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                <FormField label="Username" error={form.errors.username}>
                    <TextInput
                        name="username"
                        value={form.data.username}
                        onChange={(event) => form.setData('username', event.target.value)}
                        autoComplete="username"
                        autoCapitalize="none"
                        autoCorrect="off"
                        spellCheck={false}
                        className="h-12 text-base"
                        autoFocus
                    />
                </FormField>

                <FormField
                    label="Password"
                    error={form.errors.password}
                    hint={
                        capsLock ? (
                            <span className="flex items-center gap-1.5 font-medium text-warning-fg">
                                <TriangleAlert className="size-4" aria-hidden="true" />
                                Caps Lock is on.
                            </span>
                        ) : undefined
                    }
                >
                    <PasswordInput
                        name="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        onKeyUp={watchCapsLock}
                        onKeyDown={watchCapsLock}
                        autoComplete="current-password"
                        className="h-12 text-base"
                    />
                </FormField>

                <Button type="submit" size="lg" loading={form.processing} className="mt-1 w-full">
                    Sign In
                </Button>
            </form>

            <p className="mt-6 rounded-lg bg-surface-muted px-4 py-3 text-sm text-ink-muted">Forgot your password? Contact your system administrator.</p>

            {poweredBy !== null && (
                <p className="mt-8 flex items-center justify-center gap-2 text-xs text-ink-muted">
                    <span>Powered by</span>
                    {poweredBy.logoUrl !== null ? (
                        <img src={poweredBy.logoUrl} alt={poweredBy.name} width={48} height={34} className="h-8 w-auto" />
                    ) : (
                        <span className="font-medium text-ink">{poweredBy.name}</span>
                    )}
                </p>
            )}
        </>
    );
}
