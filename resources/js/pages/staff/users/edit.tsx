import { Head, useForm } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog } from '@/components/ui/dialog';
import { FormField, TextArea } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatusBadge } from '@/components/ui/status-badge';
import { UserForm, type CampusChoices, type RoleOption, type UserFormData } from '@/components/users/user-form';
import { useDateFormatter } from '@/lib/format';
import { routes } from '@/lib/routes';

interface EditableAccount {
    id: number;
    name: string;
    username: string;
    email: string | null;
    roleId: number;
    /** Null: the account sees every campus. */
    campusId: number | null;
    isActive: boolean;
    lastLoginAt: string | null;
    createdAt: string | null;
    twoFactor: {
        enabled: boolean;
        enabledAt: string | null;
        /** Required for this account (config two_factor.required_for). */
        required: boolean;
    };
}

interface EditUserProps extends CampusChoices {
    account: EditableAccount;
    roles: RoleOption[];
    isOwnAccount: boolean;
    canChangeCampus: boolean;
    canResetTwoFactor: boolean;
}

export default function EditUser({ account, roles, isOwnAccount, campusOptions, canChooseEveryCampus, canChangeCampus, canResetTwoFactor }: EditUserProps) {
    const formatDate = useDateFormatter();
    const [confirmingDeactivation, setConfirmingDeactivation] = useState(false);

    const form = useForm<UserFormData>({
        name: account.name,
        username: account.username,
        email: account.email ?? '',
        role_id: String(account.roleId),
        campus_id: account.campusId === null ? '' : String(account.campusId),
        is_active: account.isActive,
        password: '',
        password_confirmation: '',
    });

    const save = () => {
        // Without the right to move the account, the campus is not sent and stays as it is.
        form.transform(({ campus_id: campusId, ...data }) => (canChangeCampus ? { ...data, campus_id: campusId } : data));
        form.put(routes.users.update(account.id), {
            preserveScroll: true,
            onError: () => form.reset('password', 'password_confirmation'),
            onFinish: () => setConfirmingDeactivation(false),
        });
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Deactivation signs the user out, so it requires explicit confirmation.
        if (account.isActive && !form.data.is_active) {
            setConfirmingDeactivation(true);

            return;
        }

        save();
    };

    return (
        <>
            <Head title={`Edit ${account.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={account.name}
                    description={`Created ${formatDate.date(account.createdAt)} · Last sign-in ${
                        account.lastLoginAt ? formatDate.dateTime(account.lastLoginAt) : 'never'
                    }`}
                    breadcrumbs={[{ label: 'Users', href: routes.users.index() }, { label: account.name }]}
                />

                <UserForm
                    form={form}
                    roles={roles}
                    campusOptions={campusOptions}
                    canChooseEveryCampus={canChooseEveryCampus}
                    canChangeCampus={canChangeCampus}
                    mode="edit"
                    isOwnAccount={isOwnAccount}
                    submitLabel="Save Changes"
                    onSubmit={submit}
                />

                <TwoFactorPanel account={account} isOwnAccount={isOwnAccount} canReset={canResetTwoFactor} />
            </div>

            <ConfirmDialog
                open={confirmingDeactivation}
                title="Deactivate account?"
                description={
                    <>
                        <p>
                            {account.name} will be signed out immediately and will no longer be able to sign in.
                        </p>
                        <p>The account and its history are kept. You can reactivate it at any time.</p>
                    </>
                }
                confirmLabel="Deactivate Account"
                processing={form.processing}
                onConfirm={save}
                onCancel={() => setConfirmingDeactivation(false)}
            />
        </>
    );
}

function TwoFactorPanel({ account, isOwnAccount, canReset }: { account: EditableAccount; isOwnAccount: boolean; canReset: boolean }) {
    const formatDate = useDateFormatter();
    const [resetting, setResetting] = useState(false);
    const form = useForm({ reason: '' });
    const { enabled, enabledAt, required } = account.twoFactor;

    const close = () => {
        setResetting(false);
        form.reset();
        form.clearErrors();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.delete(routes.users.resetTwoFactor(account.id), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Panel title="Two-Step Sign-In" collapsible={false} className="mt-6">
            <div className="flex flex-col gap-3 text-sm">
                <div className="flex flex-wrap items-center gap-3">
                    {enabled ? <StatusBadge tone="success">On</StatusBadge> : <StatusBadge tone="neutral">Off</StatusBadge>}
                    {enabled && <span className="text-ink-muted">Since {formatDate.date(enabledAt)}</span>}
                    {required && <span className="text-ink-muted">Required for this account</span>}
                </div>
                {!enabled && required && <p className="text-ink-muted">They set it up the next time they sign in.</p>}
                {isOwnAccount && <p className="text-ink-muted">Manage your own on the Two-Step Sign-In page in your account menu.</p>}
                {canReset && (
                    <div>
                        <Button variant="secondary" icon={<ShieldOff className="size-4" aria-hidden="true" />} onClick={() => setResetting(true)}>
                            Reset Two-Step Sign-In
                        </Button>
                    </div>
                )}
            </div>

            <Dialog
                open={resetting}
                title="Reset two-step sign-in?"
                description={`For a lost phone. ${account.name} signs in with the password only${required ? ', then must set it up again' : ''}. Recorded in Audit History.`}
                busy={form.processing}
                onClose={close}
                footer={
                    <>
                        <Button type="button" variant="ghost" disabled={form.processing} onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" form="reset-two-factor" variant="danger" loading={form.processing}>
                            Reset
                        </Button>
                    </>
                }
            >
                <form id="reset-two-factor" onSubmit={submit} noValidate>
                    <FormField label="Reason" required error={form.errors.reason}>
                        <TextArea
                            name="reason"
                            rows={3}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            placeholder="Lost phone, confirmed in person"
                        />
                    </FormField>
                </form>
            </Dialog>
        </Panel>
    );
}
