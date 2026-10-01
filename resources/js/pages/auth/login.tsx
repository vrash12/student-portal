import { Head, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Eye, EyeOff, Headset, Info, LockKeyhole, LogIn, TriangleAlert, UserRound } from 'lucide-react';
import { useEffect, useId, useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';
import { BrandMark } from '@/components/brand-mark';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { routes } from '@/lib/routes';

/** Only the username is remembered, on this device; never the password or the session (tablets are shared). */
const REMEMBERED_USERNAME_KEY = 'sign-in.username';

function readRememberedUsername(): string {
    try {
        return window.localStorage.getItem(REMEMBERED_USERNAME_KEY) ?? '';
    } catch {
        return '';
    }
}

function storeRememberedUsername(username: string | null): void {
    try {
        if (username === null) {
            window.localStorage.removeItem(REMEMBERED_USERNAME_KEY);
        } else {
            window.localStorage.setItem(REMEMBERED_USERNAME_KEY, username);
        }
    } catch {
        // Storage unavailable (private mode): nothing is remembered.
    }
}

export default function Login() {
    const { poweredBy, login } = usePage().props.app;
    const [capsLock, setCapsLock] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [remember, setRemember] = useState(false);
    const [help, setHelp] = useState<'password' | 'assistance' | null>(null);
    const form = useForm({ username: '', password: '' });

    useEffect(() => {
        const remembered = readRememberedUsername();
        if (remembered !== '') {
            form.setData('username', remembered);
            setRemember(true);
        }
        // Runs once on load.
    }, []);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        storeRememberedUsername(remember ? form.data.username.trim() : null);
        form.post(routes.login(), {
            onFinish: () => form.reset('password'),
        });
    };

    const watchCapsLock = (event: KeyboardEvent<HTMLInputElement>) => setCapsLock(event.getModifierState('CapsLock'));
    const contact = login.helpDesk !== null ? `Contact the help desk: ${login.helpDesk}.` : 'Contact your system administrator or the academic office.';

    return (
        <>
            <Head title="Sign In" />

            <div className="mb-5 text-center">
                <BrandMark round className="mx-auto mb-4 size-32 shadow-lg sm:size-40 [@media(max-height:820px)]:size-32" />
                <h1 className="font-serif text-2xl font-bold leading-tight text-auth-navy sm:text-[1.7rem]">{login.cardTitle}</h1>
                <div className="mx-auto my-3 h-0.5 w-16 bg-accent-400" aria-hidden="true" />
                {login.tagline !== null && <p className="text-sm leading-relaxed text-ink-muted">{login.tagline}</p>}
                <p className="sr-only">Candidates sign in with their candidate number.</p>
            </div>

            <form onSubmit={submit} noValidate className="flex flex-col gap-4">
                <IconField label="Username" icon={<UserRound className="size-5" aria-hidden="true" />} error={form.errors.username}>
                    {(props) => (
                        <input
                            {...props}
                            name="username"
                            type="text"
                            placeholder="Username"
                            value={form.data.username}
                            onChange={(event) => form.setData('username', event.target.value)}
                            autoComplete="username"
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            autoFocus
                        />
                    )}
                </IconField>

                <IconField
                    label="Password"
                    icon={<LockKeyhole className="size-5" aria-hidden="true" />}
                    error={form.errors.password}
                    hint={
                        capsLock ? (
                            <span className="flex items-center gap-1.5 font-medium text-warning-fg">
                                <TriangleAlert className="size-4" aria-hidden="true" />
                                Caps Lock is on.
                            </span>
                        ) : undefined
                    }
                    trailing={
                        <button
                            type="button"
                            onClick={() => setShowPassword((shown) => !shown)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            className="flex size-10 items-center justify-center rounded-md text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-primary-600"
                        >
                            {showPassword ? <EyeOff className="size-5" aria-hidden="true" /> : <Eye className="size-5" aria-hidden="true" />}
                        </button>
                    }
                >
                    {(props) => (
                        <input
                            {...props}
                            name="password"
                            type={showPassword ? 'text' : 'password'}
                            placeholder="Password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            onKeyUp={watchCapsLock}
                            onKeyDown={watchCapsLock}
                            autoComplete="current-password"
                        />
                    )}
                </IconField>

                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-ink" title="Keeps your username on this device. Your password is never saved.">
                        <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} className="size-5 accent-primary-700" />
                        Remember me
                    </label>
                    <button
                        type="button"
                        onClick={() => setHelp((current) => (current === 'password' ? null : 'password'))}
                        aria-expanded={help === 'password'}
                        className="min-h-11 font-medium text-[#1d5fa8] hover:underline focus-visible:outline-2 focus-visible:outline-primary-600"
                    >
                        Forgot Password?
                    </button>
                </div>
                {help === 'password' && <HelpNote>Passwords are reset by your system administrator. {contact}</HelpNote>}

                <Button type="submit" size="lg" loading={form.processing} icon={<LogIn className="size-5" aria-hidden="true" />} className="h-13 w-full text-base bg-auth-navy! hover:bg-auth-navy-hover! active:bg-auth-navy!">
                    Sign In
                </Button>
            </form>

            <div className="my-3 flex items-center gap-3 text-xs uppercase tracking-widest text-ink-subtle" aria-hidden="true">
                <span className="h-px flex-1 bg-line-strong/60" />
                or
                <span className="h-px flex-1 bg-line-strong/60" />
            </div>

            <button
                type="button"
                onClick={() => setHelp((current) => (current === 'assistance' ? null : 'assistance'))}
                aria-expanded={help === 'assistance'}
                className="flex min-h-12 w-full items-center justify-center gap-2 rounded-lg border border-line-strong bg-white/70 px-4 font-serif text-sm font-semibold text-auth-navy hover:bg-white focus-visible:outline-2 focus-visible:outline-primary-600"
            >
                <Headset className="size-5" aria-hidden="true" />
                Need Assistance? Contact Help Desk
            </button>
            {help === 'assistance' && (
                <div className="mt-3">
                    <HelpNote>
                        {contact} Candidates sign in with their candidate number; staff use the username issued by the administrator.
                    </HelpNote>
                </div>
            )}

            <p className="sr-only">Remember me keeps only your username on this device. Your password is never saved.</p>

            {poweredBy !== null && (
                <p className="mt-4 flex items-center justify-center gap-2 text-xs text-ink-muted">
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

interface IconFieldProps {
    label: string;
    icon: ReactNode;
    error?: string;
    hint?: ReactNode;
    trailing?: ReactNode;
    children: (props: { id: string; className: string; 'aria-invalid': boolean | undefined; 'aria-describedby': string | undefined }) => ReactNode;
}

/**
 * Input with a leading icon and a visually hidden label (the placeholder shows
 * the field's name), with its hint and error read by screen readers.
 */
function IconField({ label, icon, error, hint, trailing, children }: IconFieldProps) {
    const id = useId();
    const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <div
                className={cn(
                    'flex h-13 items-center gap-1 rounded-lg border bg-white pl-4 pr-1 shadow-xs transition-colors focus-within:border-primary-600 focus-within:outline-2 focus-within:outline-primary-600',
                    error ? 'border-danger-border' : 'border-line-strong/70',
                )}
            >
                <span className="text-ink-muted">{icon}</span>
                {children({
                    id,
                    className: 'h-full min-w-0 flex-1 bg-transparent px-2 text-base text-ink placeholder:text-ink-subtle focus:outline-none',
                    'aria-invalid': error ? true : undefined,
                    'aria-describedby': describedBy,
                })}
                {trailing}
            </div>
            {hint && (
                <p id={`${id}-hint`} className="text-sm">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="flex items-start gap-1.5 text-sm text-danger-fg">
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{error}</span>
                </p>
            )}
        </div>
    );
}

function HelpNote({ children }: { children: ReactNode }) {
    return (
        <p role="status" className="flex items-start gap-2 rounded-lg border border-info-border bg-info-bg px-3 py-2.5 text-sm text-info-fg">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{children}</span>
        </p>
    );
}
