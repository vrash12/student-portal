import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { UserForm, type CampusChoices, type RoleOption, type UserFormData } from '@/components/users/user-form';
import { routes } from '@/lib/routes';

interface CreateUserProps extends CampusChoices {
    roles: RoleOption[];
}

export default function CreateUser({ roles, campusOptions, canChooseEveryCampus }: CreateUserProps) {
    // A campus administrator's new accounts go to their campus.
    const onlyCampus = !canChooseEveryCampus && campusOptions.length === 1 ? campusOptions[0] : undefined;
    const form = useForm<UserFormData>({
        name: '',
        username: '',
        email: '',
        role_id: '',
        campus_id: onlyCampus ? String(onlyCampus.id) : '',
        is_active: true,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(routes.users.store(), {
            preserveScroll: true,
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Create Account" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Create Account"
                    description="Create a staff account for an administrator or instructor."
                    breadcrumbs={[{ label: 'Users', href: routes.users.index() }, { label: 'Create Account' }]}
                />

                <UserForm
                    form={form}
                    roles={roles}
                    campusOptions={campusOptions}
                    canChooseEveryCampus={canChooseEveryCampus}
                    mode="create"
                    submitLabel="Create Account"
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
