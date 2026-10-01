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
import type { CandidateGroupOptions } from '@/types/candidates';

interface EditCandidateProps extends CandidateGroupOptions {
    candidate: {
        id: number;
        candidateNumber: string;
        firstName: string;
        lastName: string;
        middleName: string | null;
        suffix: string | null;
        trainingGroup: string | null;
        company: string | null;
        platoon: string | null;
        photoUrl: string | null;
        name: string;
        status: { value: string };
        classBatch: { id: number } | null;
        accountActive: boolean;
        username: string;
    };
    classOptions: ClassOptionGroup[];
    statusOptions: StatusOption[];
}

export default function EditCandidate({ candidate, classOptions, statusOptions, companyOptions, platoonOptions }: EditCandidateProps) {
    const [confirmingDeactivation, setConfirmingDeactivation] = useState(false);

    const form = useForm<CandidateFormData>({
        candidate_number: candidate.candidateNumber,
        first_name: candidate.firstName,
        last_name: candidate.lastName,
        middle_name: candidate.middleName ?? '',
        suffix: candidate.suffix ?? '',
        training_group: candidate.trainingGroup ?? '',
        company: candidate.company ?? '',
        platoon: candidate.platoon ?? '',
        profile_photo: null,
        remove_photo: false,
        class_batch_id: candidate.classBatch ? String(candidate.classBatch.id) : '',
        status: candidate.status.value,
        account_active: candidate.accountActive,
        password: '',
        password_confirmation: '',
    });

    const save = () => {
        form.transform((data) => ({ ...data, _method: 'put' }));
        form.post(routes.candidates.update(candidate.id), {
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
                    companyOptions={companyOptions}
                    platoonOptions={platoonOptions}
                    currentUsername={candidate.username}
                    currentPhotoUrl={candidate.photoUrl}
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
