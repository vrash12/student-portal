import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button, ButtonLink } from '@/components/ui/button';
import { FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';
import { FormActions, FormSection } from '@/components/ui/form-section';
import { PageHeader } from '@/components/ui/page-header';
import { routes } from '@/lib/routes';
import { terms } from '@/lib/terminology';

interface Option {
    value: string;
    label: string;
}

interface EducationEntry {
    level: string;
    degree: string;
    school: string;
    year_graduated: string;
    honors: string;
}

interface BackgroundFormData {
    date_of_birth: string;
    place_of_birth: string;
    sex: string;
    civil_status: string;
    home_address: string;
    mobile_number: string;
    personal_email: string;
    emergency_contact_name: string;
    emergency_contact_relationship: string;
    emergency_contact_phone: string;
    eligibility: string;
    prior_service: string;
    previous_occupation: string;
    education: EducationEntry[];
}

interface CandidateBackgroundEditProps {
    candidate: { id: number; name: string; candidateNumber: string };
    background: BackgroundFormData;
    options: { sex: Option[]; civilStatus: Option[]; educationLevel: Option[] };
}

/** At most this many education entries (CandidateEducation::MAX_ENTRIES). */
const MAX_EDUCATION = 6;

/**
 * Edit a candidate's background record (owner request, 2026-10-03). Every
 * field is optional; an education entry needs its level, degree and school.
 * The server validates and audits the change.
 */
export default function CandidateBackgroundEdit({ candidate, background, options }: CandidateBackgroundEditProps) {
    const form = useForm<BackgroundFormData>(background);
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(routes.candidates.background.update(candidate.id), { preserveScroll: true });
    };

    const text = (field: Exclude<keyof BackgroundFormData, 'education'>, label: string, props: { type?: string; maxLength?: number; autoComplete?: string; inputMode?: 'tel' | 'email' } = {}) => (
        <FormField label={label} error={errors[field]}>
            <TextInput
                type={props.type ?? 'text'}
                value={form.data[field]}
                maxLength={props.maxLength}
                autoComplete={props.autoComplete ?? 'off'}
                inputMode={props.inputMode}
                onChange={(event) => form.setData(field, event.target.value)}
            />
        </FormField>
    );

    const setEntry = (index: number, field: keyof EducationEntry, value: string) =>
        form.setData(
            'education',
            form.data.education.map((entry, position) => (position === index ? { ...entry, [field]: value } : entry)),
        );

    return (
        <>
            <Head title={`Background of ${candidate.name}`} />

            <div className="mx-auto max-w-4xl">
                <PageHeader
                    title="Edit Background"
                    description={`${candidate.name} · Candidate ${candidate.candidateNumber}. Every field is optional.`}
                    breadcrumbs={[
                        { label: terms.candidate.plural, href: routes.candidates.index() },
                        { label: candidate.name, href: routes.candidates.show(candidate.id) },
                        { label: 'Edit Background' },
                    ]}
                />

                <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                    <FormSection title="Personal Details" description="Shown to administrators and the candidate, never to instructors.">
                        <div className="grid gap-5 sm:grid-cols-2">
                            {text('date_of_birth', 'Date of Birth', { type: 'date' })}
                            {text('place_of_birth', 'Place of Birth', { maxLength: 150 })}
                            <FormField label="Sex" error={errors.sex}>
                                <SelectInput value={form.data.sex} onChange={(event) => form.setData('sex', event.target.value)}>
                                    <option value="">Not recorded</option>
                                    {options.sex.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Civil Status" error={errors.civil_status}>
                                <SelectInput value={form.data.civil_status} onChange={(event) => form.setData('civil_status', event.target.value)}>
                                    <option value="">Not recorded</option>
                                    {options.civilStatus.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            {text('mobile_number', 'Mobile Number', { type: 'tel', maxLength: 30, inputMode: 'tel' })}
                            {text('personal_email', 'Personal Email', { type: 'email', maxLength: 150, inputMode: 'email' })}
                        </div>
                        <FormField label="Home Address" error={errors.home_address}>
                            <TextArea rows={2} value={form.data.home_address} maxLength={255} onChange={(event) => form.setData('home_address', event.target.value)} />
                        </FormField>
                    </FormSection>

                    <FormSection title="Emergency Contact" description="Who to call for the candidate.">
                        <div className="grid gap-5 sm:grid-cols-3">
                            {text('emergency_contact_name', 'Name', { maxLength: 150 })}
                            {text('emergency_contact_relationship', 'Relationship', { maxLength: 50 })}
                            {text('emergency_contact_phone', 'Phone', { type: 'tel', maxLength: 30, inputMode: 'tel' })}
                        </div>
                    </FormSection>

                    <FormSection title="Education" description="Bachelor's degree and any other education, highest or most recent first.">
                        {errors.education && <p className="text-sm text-danger-fg">{errors.education}</p>}
                        {form.data.education.length === 0 && <p className="text-sm text-ink-muted">No education recorded yet.</p>}
                        <ol className="flex flex-col gap-4">
                            {form.data.education.map((entry, index) => (
                                <li key={index} className="rounded-lg border border-line-box p-4">
                                    <div className="mb-3 flex items-center justify-between gap-3">
                                        <h3 className="text-sm font-semibold text-ink">Education {index + 1}</h3>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            icon={<Trash2 className="size-4" aria-hidden="true" />}
                                            onClick={() => form.setData('education', form.data.education.filter((_, position) => position !== index))}
                                        >
                                            Remove<span className="sr-only"> education {index + 1}</span>
                                        </Button>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField label="Level" required error={errors[`education.${index}.level`]}>
                                            <SelectInput value={entry.level} onChange={(event) => setEntry(index, 'level', event.target.value)}>
                                                {options.educationLevel.map((option) => (
                                                    <option key={option.value} value={option.value}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </SelectInput>
                                        </FormField>
                                        <FormField label="Degree or Course" required error={errors[`education.${index}.degree`]}>
                                            <TextInput value={entry.degree} maxLength={150} placeholder="e.g. BS Criminology" onChange={(event) => setEntry(index, 'degree', event.target.value)} />
                                        </FormField>
                                        <FormField label="School" required error={errors[`education.${index}.school`]}>
                                            <TextInput value={entry.school} maxLength={150} onChange={(event) => setEntry(index, 'school', event.target.value)} />
                                        </FormField>
                                        <div className="grid grid-cols-2 gap-4">
                                            <FormField label="Year Graduated" error={errors[`education.${index}.year_graduated`]}>
                                                <TextInput value={entry.year_graduated} inputMode="numeric" maxLength={4} onChange={(event) => setEntry(index, 'year_graduated', event.target.value)} />
                                            </FormField>
                                            <FormField label="Honors" error={errors[`education.${index}.honors`]}>
                                                <TextInput value={entry.honors} maxLength={100} placeholder="e.g. Cum Laude" onChange={(event) => setEntry(index, 'honors', event.target.value)} />
                                            </FormField>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        {form.data.education.length < MAX_EDUCATION && (
                            <div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon={<Plus className="size-4" aria-hidden="true" />}
                                    onClick={() =>
                                        form.setData('education', [...form.data.education, { level: 'bachelor', degree: '', school: '', year_graduated: '', honors: '' }])
                                    }
                                >
                                    Add Education
                                </Button>
                            </div>
                        )}
                    </FormSection>

                    <FormSection title="Service Background" description="Also shown to the instructors of the candidate's class.">
                        {text('eligibility', 'Eligibility / Licenses', { maxLength: 255 })}
                        <div className="grid gap-5 sm:grid-cols-2">
                            {text('prior_service', 'Prior Military or Reserve Service', { maxLength: 255 })}
                            {text('previous_occupation', 'Previous Occupation', { maxLength: 150 })}
                        </div>
                    </FormSection>

                    <FormActions>
                        <ButtonLink href={routes.candidates.show(candidate.id)} variant="secondary">
                            Cancel
                        </ButtonLink>
                        <Button type="submit" loading={form.processing}>
                            Save Background
                        </Button>
                    </FormActions>
                </form>
            </div>
        </>
    );
}
