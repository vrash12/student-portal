import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, KeyRound, ShieldCheck } from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';

interface TwoFactorChallengeProps {
    username: string;
}

/**
 * Second step of signing in: the 6-digit code from the authenticator app, or
 * a recovery code. Nobody is signed in until the server accepts the code.
 */
export default function TwoFactorChallenge({ username }: TwoFactorChallengeProps) {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });
    const inputRef = useRef<HTMLInputElement>(null);
    const inputId = useId();
    const error = useRecoveryCode ? form.errors.recovery_code : form.errors.code;

    useEffect(() => {
        inputRef.current?.focus();
    }, [useRecoveryCode]);

    const switchMethod = () => {
        form.reset();
        form.clearErrors();
        setUseRecoveryCode((current) => !current);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => (useRecoveryCode ? { recovery_code: data.recovery_code } : { code: data.code }));
        form.post(routes.twoFactorChallenge(), {
            onError: () => form.reset(),
        });
    };

    return (
        <>
            <Head title="Two-Step Sign-In" />

            <div className="mb-5 text-center">
                <BrandMark round className="mx-auto mb-4 size-24 shadow-lg" />
                <h1 className="font-serif text-2xl font-bold leading-tight text-auth-navy">Two-Step Sign-In</h1>
                <div className="mx-auto my-3 h-0.5 w-16 bg-accent-400" aria-hidden="true" />
                <p className="text-sm leading-relaxed text-ink-muted">
                    {useRecoveryCode ? (
                        <>Enter one of the recovery codes you saved for <strong className="font-semibold text-ink">{username}</strong>. Each code works once.</>
                    ) : (
                        <>Open your authenticator app and enter the 6-digit code for <strong className="font-semibold text-ink">{username}</strong>.</>
                    )}
                </p>
            </div>

            <form onSubmit={submit} noValidate className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <label htmlFor={inputId} className="text-sm font-medium text-ink">
                        {useRecoveryCode ? 'Recovery code' : 'Sign-in code'}
                    </label>
                    <div
                        className={cn(
                            'flex h-13 items-center gap-2 rounded-lg border bg-white px-4 shadow-xs focus-within:border-primary-600 focus-within:outline-2 focus-within:outline-primary-600',
                            error ? 'border-danger-border' : 'border-line-strong/70',
                        )}
                    >
                        {useRecoveryCode ? (
                            <KeyRound className="size-5 text-ink-muted" aria-hidden="true" />
                        ) : (
                            <ShieldCheck className="size-5 text-ink-muted" aria-hidden="true" />
                        )}
                        {useRecoveryCode ? (
                            <input
                                ref={inputRef}
                                id={inputId}
                                name="recovery_code"
                                type="text"
                                value={form.data.recovery_code}
                                onChange={(event) => form.setData('recovery_code', event.target.value)}
                                placeholder="xxxxx-xxxxx"
                                autoComplete="off"
                                autoCapitalize="none"
                                autoCorrect="off"
                                spellCheck={false}
                                maxLength={30}
                                aria-invalid={error ? true : undefined}
                                aria-describedby={error ? `${inputId}-error` : undefined}
                                className="h-full min-w-0 flex-1 bg-transparent font-mono text-lg tracking-wider text-ink placeholder:text-ink-subtle focus:outline-none"
                            />
                        ) : (
                            <input
                                ref={inputRef}
                                id={inputId}
                                name="code"
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9 ]*"
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value.replace(/[^\d ]/g, ''))}
                                placeholder="123 456"
                                autoComplete="one-time-code"
                                maxLength={7}
                                aria-invalid={error ? true : undefined}
                                aria-describedby={error ? `${inputId}-error` : undefined}
                                className="h-full min-w-0 flex-1 bg-transparent font-mono text-2xl tracking-[0.3em] text-ink placeholder:text-ink-subtle focus:outline-none"
                            />
                        )}
                    </div>
                    {error && (
                        <p id={`${inputId}-error`} className="flex items-start gap-1.5 text-sm text-danger-fg">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span>{error}</span>
                        </p>
                    )}
                </div>

                <Button
                    type="submit"
                    size="lg"
                    loading={form.processing}
                    icon={<ShieldCheck className="size-5" aria-hidden="true" />}
                    className="h-13 w-full text-base bg-auth-navy! hover:bg-auth-navy-hover! active:bg-auth-navy!"
                >
                    Verify and Sign In
                </Button>
            </form>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
                <Link href={routes.login()} className="flex min-h-11 items-center gap-1.5 font-medium text-ink-muted hover:text-ink">
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Back to Sign In
                </Link>
                <button
                    type="button"
                    onClick={switchMethod}
                    className="min-h-11 font-medium text-[#1d5fa8] hover:underline focus-visible:outline-2 focus-visible:outline-primary-600"
                >
                    {useRecoveryCode ? 'Use the authenticator app' : 'Lost your phone? Use a recovery code'}
                </button>
            </div>
        </>
    );
}
