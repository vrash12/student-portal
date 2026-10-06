import { CheckboxField, FormField, SelectInput, TextArea, TextInput } from '@/components/ui/form-field';

/** A class the user may schedule, with its subjects (and who teaches them) and academic year. */
export interface ScheduleClassOption {
    id: number;
    name: string;
    campusId: number;
    period: { name: string; isActive: boolean; startsOn: string; endsOn: string };
    subjects: Array<{ id: number; name: string; instructorIds: number[] }>;
}

export interface ScheduleInstructorOption {
    id: number;
    name: string;
    campusId: number;
}

/** Form fields of a schedule entry, as the server expects them. */
export interface ScheduleEntryData {
    class_subject_id: string;
    instructor_id: string;
    title: string;
    location: string;
    starts_on: string;
    repeats_weekly: boolean;
    ends_on: string;
    start_time: string;
    end_time: string;
    notes: string;
}

interface ScheduleEntryFieldsProps {
    data: ScheduleEntryData;
    errors: Partial<Record<keyof ScheduleEntryData, string>>;
    classOption: ScheduleClassOption | null;
    instructors: ScheduleInstructorOption[];
    onChange: (changes: Partial<ScheduleEntryData>) => void;
}

/** What, who, where and when of a schedule entry (add and edit). */
export function ScheduleEntryFields({ data, errors, classOption, instructors, onChange }: ScheduleEntryFieldsProps) {
    const subjects = classOption?.subjects ?? [];
    const campusInstructors = instructors.filter((instructor) => instructor.campusId === classOption?.campusId);
    const period = classOption?.period;

    // Choosing a subject fills an empty title and instructor with the subject's.
    const chooseSubject = (value: string) => {
        const subject = subjects.find((option) => String(option.id) === value);
        const changes: Partial<ScheduleEntryData> = { class_subject_id: value };
        if (subject !== undefined && data.title.trim() === '') {
            changes.title = subject.name;
        }
        if (subject !== undefined && data.instructor_id === '' && subject.instructorIds[0] !== undefined) {
            changes.instructor_id = String(subject.instructorIds[0]);
        }
        onChange(changes);
    };

    return (
        <>
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField label="Subject" error={errors.class_subject_id} hint="Optional. Leave blank for formations, drills or other training.">
                    <SelectInput name="class_subject_id" value={data.class_subject_id} onChange={(event) => chooseSubject(event.target.value)}>
                        <option value="">No subject</option>
                        {subjects.map((subject) => (
                            <option key={subject.id} value={String(subject.id)}>
                                {subject.name}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
                <FormField label="Instructor" error={errors.instructor_id} hint="Optional. Teaching staff of the class's campus.">
                    <SelectInput name="instructor_id" value={data.instructor_id} onChange={(event) => onChange({ instructor_id: event.target.value })}>
                        <option value="">No instructor</option>
                        {campusInstructors.map((instructor) => (
                            <option key={instructor.id} value={String(instructor.id)}>
                                {instructor.name}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
            </div>
            <FormField label="Title" required error={errors.title} hint="e.g. Subject 1 lecture, Physical training, Morning formation.">
                <TextInput name="title" value={data.title} onChange={(event) => onChange({ title: event.target.value })} maxLength={150} autoComplete="off" />
            </FormField>
            <FormField label="Room or field" error={errors.location} hint="Optional, e.g. Room 2, Parade ground.">
                <TextInput name="location" value={data.location} onChange={(event) => onChange({ location: event.target.value })} maxLength={120} autoComplete="off" />
            </FormField>
            <div className="grid gap-5 sm:grid-cols-3">
                <FormField label={data.repeats_weekly ? 'First date' : 'Date'} required error={errors.starts_on}>
                    <TextInput type="date" name="starts_on" value={data.starts_on} min={period?.startsOn} max={period?.endsOn} onChange={(event) => onChange({ starts_on: event.target.value })} />
                </FormField>
                <FormField label="Starts" required error={errors.start_time}>
                    <TextInput type="time" name="start_time" value={data.start_time} onChange={(event) => onChange({ start_time: event.target.value })} />
                </FormField>
                <FormField label="Ends" required error={errors.end_time}>
                    <TextInput type="time" name="end_time" value={data.end_time} onChange={(event) => onChange({ end_time: event.target.value })} />
                </FormField>
            </div>
            <CheckboxField
                name="repeats_weekly"
                label="Every week"
                description="Repeats on the same weekday as the first date, until the last date."
                checked={data.repeats_weekly}
                onChange={(event) => onChange({ repeats_weekly: event.target.checked, ends_on: data.ends_on === '' ? (period?.endsOn ?? '') : data.ends_on })}
                error={errors.repeats_weekly}
            />
            {data.repeats_weekly && (
                <FormField
                    label="Last date"
                    required
                    error={errors.ends_on}
                    hint={period ? `No later than the end of the academic year ${period.name}.` : undefined}
                    className="sm:w-1/3"
                >
                    <TextInput type="date" name="ends_on" value={data.ends_on} min={data.starts_on || period?.startsOn} max={period?.endsOn} onChange={(event) => onChange({ ends_on: event.target.value })} />
                </FormField>
            )}
            <FormField label="Notes" error={errors.notes} hint="Optional. What to bring or wear. Candidates of the class see it. Up to 300 characters.">
                <TextArea name="notes" value={data.notes} onChange={(event) => onChange({ notes: event.target.value })} maxLength={300} rows={2} />
            </FormField>
        </>
    );
}
