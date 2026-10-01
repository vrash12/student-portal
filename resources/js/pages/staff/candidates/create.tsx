import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CandidateForm, type CandidateFormData, type ClassOptionGroup } from '@/components/candidates/candidate-form';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import type { CandidateGroupOptions } from '@/types/candidates';

interface CreateCandidateProps extends CandidateGroupOptions {
    classOptions: ClassOptionGroup[];
}

export default function CreateCandidate({ classOptions, companyOptions, platoonOptions }: CreateCandidateProps) {
    const form = useForm<CandidateFormData>({
        candidate_number: '',
        first_name: '',
        last_name: '',
        middle_name: '',
        suffix: '',
        training_group: '',
        company: '',
        platoon: '',
        profile_photo: null,
        remove_photo: false,
        class_batch_id: '',
        status: 'enrolled',
        account_active: true,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            candidate_number: data.candidate_number,
            first_name: data.first_name,
            last_name: data.last_name,
            middle_name: data.middle_name,
            suffix: data.suffix,
            training_group: data.training_group,
            company: data.company,
            platoon: data.platoon,
            profile_photo: data.profile_photo,
            class_batch_id: data.class_batch_id,
            password: data.password,
            password_confirmation: data.password_confirmation,
        }));
        form.post(routes.candidates.store(), {
            preserveScroll: true,
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Create Candidate" />

            <div className="mx-auto max-w-3xl">
                <PageHeader
                    title="Create Candidate"
                    description="Creates the candidate record and the account used to sign in on examination tablets."
                    breadcrumbs={[{ label: 'Candidates', href: routes.candidates.index() }, { label: 'Create Candidate' }]}
                />
                <CandidateForm
                    form={form}
                    mode="create"
                    classOptions={classOptions}
                    companyOptions={companyOptions}
                    platoonOptions={platoonOptions}
                    submitLabel="Create Candidate"
                    cancelHref={routes.candidates.index()}
                    onSubmit={submit}
                />
            </div>
        </>
    );
}
