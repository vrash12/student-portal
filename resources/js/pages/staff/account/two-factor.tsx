import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Download, KeyRound, ShieldCheck, ShieldOff, Smartphone } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { FormField, PasswordInput, TextInput } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';

interface PendingSetup {
    /** The QR code as an SVG data address. */
    qrCode: string;
    /** The secret in groups of four, for typing into the app by hand. */
    secret: string;
    issuer: string;
    account: string;
}

interface TwoFactorProps {
    enabled: boolean;
    enabledAt: string | null;
    required: boolean;
    recoveryCodesLeft: number;
    setup: PendingSetup | null;
    /** Shown once, right after they are made. */
    newRecoveryCodes: string[] | null;
}

type PasswordAction = 'setup' | 'recovery-codes' | 'disable';

const PASSWORD_ACTIONS: Record<PasswordAction, { title: string; description: string; confirmLabel: string; danger?: boolean }> = {
    setup: {
        title: 'Set Up Two-Step Sign-In',
        description: 'Enter your password to start.',
        confirmLabel: 'Continue',
    },
    'recovery-codes': {
        title: 'Make New Recovery Codes',
        description: 'Your current recovery codes will stop working.',
        confirmLabel: 'Make New Codes',
    },
    disable: {
        title: 'Turn Off Two-Step Sign-In',
        description: 'You will sign in with your password only.',
        confirmLabel: 'Turn Off',
        danger: true,
    },
};

export default function AccountTwoFactor({ enabled, enabledAt, required, recoveryCodesLeft, setup, newRecoveryCodes }: TwoFactorProps) {
    const formatDate = useDateFormatter();
    const [passwordAction, setPasswordAction] = useState<PasswordAction | null>(null);

    return (
        <>
            <Head title="Two-Step Sign-In" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Two-Step Sign-In"
                    description="After your password, enter a 6-digit code from an authenticator app. Works without internet."
                />

                <div className="flex flex-col gap-5">
                    {required && !enabled && (
                        <Alert tone="warning" title="Required for your account">
                            Set up two-step sign-in to continue using the system.
                        </Alert>
                    )}

                    {newRecoveryCodes !== null && <RecoveryCodes codes={newRecoveryCodes} />}

                    {enabled ? (
                        <Panel title="Status" collapsible={false}>
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <StatusBadge tone="success">On</StatusBadge>
                                    <span className="text-sm text-ink-muted">Since {formatDate.date(enabledAt)}</span>
                                </div>
                                <p className="text-sm text-ink">
                                    Recovery codes left: <strong className="font-semibold">{recoveryCodesLeft}</strong>
                                    {recoveryCodesLeft <= 2 && <span className="text-warning-fg"> — make new ones soon.</span>}
                                </p>
                                <div className="flex flex-wrap gap-3">
                                    <Button variant="secondary" icon={<KeyRound className="size-4" aria-hidden="true" />} onClick={() => setPasswordAction('recovery-codes')}>
                                        Make New Recovery Codes
                                    </Button>
                                    {!required && (
                                        <Button variant="ghost" icon={<ShieldOff className="size-4" aria-hidden="true" />} onClick={() => setPasswordAction('disable')}>
                                            Turn Off
                                        </Button>
                                    )}
                                </div>
                                {required && <p className="text-sm text-ink-muted">Required for your account; it cannot be turned off.</p>}
                            </div>
                        </Panel>
                    ) : setup !== null ? (
                        <SetupSteps setup={setup} />
                    ) : (
                        <Panel title="Status" collapsible={false}>
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <StatusBadge tone="neutral">Off</StatusBadge>
                                </div>
                                <p className="text-sm text-ink">
                                    You need an authenticator app on your phone (Microsoft Authenticator, Google Authenticator, Aegis, FreeOTP or 2FAS) or a TOTP
                                    hardware token from IT.
                                </p>
                                <div>
                                    <Button icon={<ShieldCheck className="size-4" aria-hidden="true" />} onClick={() => setPasswordAction('setup')}>
                                        Set Up
                                    </Button>
                                </div>
                            </div>
                        </Panel>
                    )}
                </div>
            </div>

            {passwordAction !== null && <PasswordDialog action={passwordAction} onClose={() => setPasswordAction(null)} />}
        </>
    );
}

function SetupSteps({ setup }: { setup: PendingSetup }) {
    const form = useForm({ code: '' });
    const [cancelling, setCancelling] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.account.twoFactor.confirm(), {
            preserveScroll: true,
            onError: () => form.reset('code'),
        });
    };

    const cancel = () => {
        setCancelling(true);
        router.post(routes.account.twoFactor.cancel(), {}, { onFinish: () => setCancelling(false) });
    };

    return (
        <Panel title="Set Up" collapsible={false}>
            <ol className="flex flex-col gap-6">
                <li className="flex gap-3">
                    <StepNumber>1</StepNumber>
                    <div className="text-sm text-ink">
                        <p className="font-semibold">Open your authenticator app</p>
                        <p className="mt-1 text-ink-muted">
                            <Smartphone className="mr-1 inline size-4 align-text-bottom" aria-hidden="true" />
                            Choose “Add account” or “+”, then scan a QR code.
                        </p>
                    </div>
                </li>
                <li className="flex gap-3">
                    <StepNumber>2</StepNumber>
                    <div className="flex min-w-0 flex-col gap-3 text-sm text-ink">
                        <p className="font-semibold">Scan this QR code</p>
                        <img
                            src={setup.qrCode}
                            alt={`QR code to add ${setup.issuer}: ${setup.account} to an authenticator app`}
                            width={240}
                            height={240}
                            className="size-60 rounded-lg border border-line-box bg-white p-2"
                        />
                        <div>
                            <p className="text-ink-muted">Cannot scan? Type this key in the app (time-based):</p>
                            <p className="mt-1 break-all rounded-md bg-surface-muted px-3 py-2 font-mono text-base tracking-wider" aria-label="Setup key">
                                {setup.secret}
                            </p>
                        </div>
                        <p className="text-ink-muted">Keep this key private. Anyone with it can make your codes.</p>
                    </div>
                </li>
                <li className="flex gap-3">
                    <StepNumber>3</StepNumber>
                    <form onSubmit={submit} noValidate className="flex min-w-0 flex-1 flex-col gap-3">
                        <p className="text-sm font-semibold text-ink">Enter the 6-digit code the app shows</p>
                        <FormField label="Code" required error={form.errors.code} className="max-w-xs">
                            <TextInput
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={7}
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value.replace(/[^\d ]/g, ''))}
                                className="font-mono text-lg tracking-[0.3em]"
                            />
                        </FormField>
                        <div className="flex flex-wrap gap-3">
                            <Button type="submit" loading={form.processing} icon={<ShieldCheck className="size-4" aria-hidden="true" />}>
                                Turn On
                            </Button>
                            <Button type="button" variant="ghost" loading={cancelling} disabled={form.processing} onClick={cancel}>
                                Cancel Setup
                            </Button>
                        </div>
                    </form>
                </li>
            </ol>
        </Panel>
    );
}

function StepNumber({ children }: { children: string }) {
    return (
        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-800" aria-hidden="true">
            {children}
        </span>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);
    const text = codes.join('\n');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    const download = () => {
        const blob = new Blob([`Recovery codes (each works once)\n\n${text}\n`], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'recovery-codes.txt';
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <Panel title="Your Recovery Codes" description="Shown only now. Each code signs you in once if you lose your phone." collapsible={false}>
            <div className="flex flex-col gap-4">
                <Alert tone="warning">Write them down or print them and keep them somewhere safe, away from your phone.</Alert>
                <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Recovery codes">
                    {codes.map((code) => (
                        <li key={code} className="rounded-md bg-surface-muted px-3 py-2 text-center font-mono text-sm tracking-wide text-ink">
                            {code}
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="secondary"
                        icon={copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
                        onClick={copy}
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button variant="secondary" icon={<Download className="size-4" aria-hidden="true" />} onClick={download}>
                        Download
                    </Button>
                </div>
            </div>
        </Panel>
    );
}

function PasswordDialog({ action, onClose }: { action: PasswordAction; onClose: () => void }) {
    const form = useForm({ current_password: '' });
    const config = PASSWORD_ACTIONS[action];

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose, onError: () => form.reset() };
        if (action === 'setup') {
            form.post(routes.account.twoFactor.store(), options);
        } else if (action === 'recovery-codes') {
            form.post(routes.account.twoFactor.recoveryCodes(), options);
        } else {
            form.delete(routes.account.twoFactor.destroy(), options);
        }
    };

    return (
        <Dialog
            open
            title={config.title}
            description={config.description}
            busy={form.processing}
            onClose={onClose}
            footer={
                <>
                    <Button type="button" variant="ghost" disabled={form.processing} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" form="two-factor-password" variant={config.danger ? 'danger' : 'primary'} loading={form.processing}>
                        {config.confirmLabel}
                    </Button>
                </>
            }
        >
            <form id="two-factor-password" onSubmit={submit} noValidate>
                <FormField label="Password" required error={form.errors.current_password}>
                    <PasswordInput
                        name="current_password"
                        autoComplete="current-password"
                        autoFocus
                        value={form.data.current_password}
                        onChange={(event) => form.setData('current_password', event.target.value)}
                    />
                </FormField>
            </form>
        </Dialog>
    );
}
