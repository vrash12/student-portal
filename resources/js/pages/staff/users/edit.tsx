import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { PageHeader } from '@/components/ui/page-header';
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
}

interface EditUserProps extends CampusChoices {
    account: EditableAccount;
    roles: RoleOption[];
    isOwnAccount: boolean;
    canChangeCampus: boolean;
}

export default function EditUser({ account, roles, isOwnAccount, campusOptions, canChooseEveryCampus, canChangeCampus }: EditUserProps) {
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
