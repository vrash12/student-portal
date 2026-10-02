export type MedicalFieldTypeValue = 'text' | 'long_text' | 'choice' | 'date' | 'yes_no';

export interface MedicalFieldTypeOption {
    value: MedicalFieldTypeValue;
    label: string;
    description: string;
}

/** A medical record field as defined by administrators (MedicalRecordPresenter::field). */
export interface MedicalFieldDefinition {
    id: number;
    name: string;
    type: { value: MedicalFieldTypeValue; label: string };
    /** Choice fields only. */
    options: string[];
    helpText: string | null;
    sortOrder: number;
    visibleToInstructors: boolean;
    visibleToCandidate: boolean;
    isActive: boolean;
}

/** One field of a candidate's record with its value (null = not recorded). */
export interface MedicalEntry {
    fieldId: number;
    name: string;
    type: MedicalFieldTypeValue;
    helpText: string | null;
    visibleToInstructors: boolean;
    visibleToCandidate: boolean;
    value: string | null;
}

/** The medical panel of the staff candidate profile. */
export interface ProfileMedical {
    /** "full": every field (medical staff); "instructor": only the fields shared with instructors. */
    scope: 'full' | 'instructor';
    entries: MedicalEntry[];
    canEdit: boolean;
    updatedAt: string | null;
    /** Full scope only. */
    updatedBy: string | null;
}
