import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import {
    CandidateForm,
    type CandidateFormData,
    type ClassOptionGroup,
    type StatusOption,
} from '@/components/candidates/candidate-form';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';

interface EditCandidateProps {
    candidate: {
        id: number;
        candidateNumber: string;
        firstName: string;
        lastName: string;
        name: string;
        status: { value: string };
        classBatch: { id: number } | null;
        accountActive: boolean;
        username: string;
    };
    classOptions: ClassOptionGroup[];
    statusOptions: StatusOption[];
}

export default function EditCandidate({ candidate, classOptions, statusOptions }: EditCandidateProps) {
    const [confirmingDeactivation, setConfirmingDeactivation] = useState(false);

    const form = useForm<CandidateFormData>({
        candidate_number: candidate.candidateNumber,
        first_name: candidate.firstName,
        last_name: candidate.lastName,
        class_batch_id: candidate.classBatch ? String(candidate.classBatch.id) : '',
        status: candidate.status.value,
        account_active: candidate.accountActive,
        password: '',
        password_confirmation: '',
    });

    const save = () => {
        form.put(routes.candidates.update(candidate.id), {
            preserveScroll: true,
            onError: () => form.reset('password', 'password_confirmation'),
            onFinish: () => setConfirmingDeactivation(false),
        });
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (candidate.accountActive && !form.data.account_active) {
            setConfirmingDeactivation(true);

            return;
        }

        save();
    };

    return (
        <>
            <Head title={`Edit ${candidate.name}`} />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title={`Edit ${candidate.name}`}
                    description={`Candidate ${candidate.candidateNumber}`}
                    breadcrumbs={[
                        { label: 'Candidates', href: routes.candidates.index() },
                        { label: candidate.name, href: routes.candidates.show(candidate.id) },
                        { label: 'Edit' },
                    ]}
                />
                <CandidateForm
                    form={form}
                    mode="edit"
                    classOptions={classOptions}
                    statusOptions={statusOptions}
                    currentUsername={candidate.username}
                    submitLabel="Save Changes"
                    cancelHref={routes.candidates.show(candidate.id)}
                    onSubmit={submit}
                />
            </div>

            <ConfirmDialog
                open={confirmingDeactivation}
                title="Deactivate candidate account?"
                description={
                    <>
                        <p>{candidate.name} will be signed out and will not be able to sign in to the examination portal.</p>
                        <p>The candidate record and its history are kept. You can reactivate the account at any time.</p>
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
