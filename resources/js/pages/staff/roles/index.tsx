import { Head } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { Fragment } from 'react';
import { Alert } from '@/components/ui/alert';
import { PageHeader } from '@/components/ui/page-header';

interface RoleSummary {
    id: number;
    code: string;
    name: string;
    description: string | null;
    isSystem: boolean;
    userCount: number;
    permissions: string[];
}

interface PermissionGroup {
    group: string;
    permissions: Array<{ code: string; label: string; description: string }>;
}

interface RolesIndexProps {
    roles: RoleSummary[];
    permissionGroups: PermissionGroup[];
}

export default function RolesIndex({ roles, permissionGroups }: RolesIndexProps) {
    return (
        <>
            <Head title="Roles & Permissions" />

            <PageHeader
                title="Roles & Permissions"
                description="Each role grants a set of permissions. Accounts receive permissions through their role."
            />

            <Alert className="mb-6">
                System roles and their permissions are defined by the application. Editing role permissions is not
                available in this version.
            </Alert>

            <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                <table className="w-full min-w-[48rem] text-left text-sm">
                    <caption className="sr-only">Permissions granted by each role</caption>
                    <thead className="bg-surface-muted">
                        <tr>
                            <th scope="col" className="w-2/5 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                Permission
                            </th>
                            {roles.map((role) => (
                                <th key={role.id} scope="col" className="px-4 py-3 text-center align-bottom">
                                    <span className="block text-sm font-semibold text-ink">{role.name}</span>
                                    <span className="block text-xs font-normal text-ink-muted tabular-nums">
                                        {role.userCount} {role.userCount === 1 ? 'account' : 'accounts'}
                                    </span>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {permissionGroups.map((group) => (
                            <Fragment key={group.group}>
                                <tr className="border-t border-line bg-canvas">
                                    <th
                                        scope="colgroup"
                                        colSpan={roles.length + 1}
                                        className="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-muted"
                                    >
                                        {group.group}
                                    </th>
                                </tr>
                                {group.permissions.map((permission) => (
                                    <tr key={permission.code} className="border-t border-line">
                                        <th scope="row" className="px-4 py-3 font-normal">
                                            <span className="block font-medium text-ink">{permission.label}</span>
                                            <span className="block text-ink-muted">{permission.description}</span>
                                        </th>
                                        {roles.map((role) => {
                                            const granted = role.permissions.includes(permission.code);

                                            return (
                                                <td key={role.id} className="px-4 py-3 text-center">
                                                    {granted ? (
                                                        <Check className="mx-auto size-5 text-success-fg" aria-hidden="true" />
                                                    ) : (
                                                        <Minus className="mx-auto size-5 text-ink-subtle" aria-hidden="true" />
                                                    )}
                                                    <span className="sr-only">{granted ? 'Granted' : 'Not granted'}</span>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
