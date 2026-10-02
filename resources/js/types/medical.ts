import type { StatusTone } from '@/components/ui/status-badge';

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

/** An instructor's access to the full record and their requests for it. */
export interface MedicalAccessState {
    /** Approved, unexpired access. */
    grant: { requestId: number; expiresAt: string | null; grantedBy: string | null } | null;
    pending: { requestId: number; requestedAt: string | null } | null;
    /** The last answer: rejected, withdrawn, or ended access. */
    lastDecision: { status: { value: string; label: string; tone: StatusTone }; note: string | null; at: string | null } | null;
    canRequest: boolean;
}

/** The medical panel of the staff candidate profile. */
export interface ProfileMedical {
    /**
     * "full": every field (medical staff); "instructor": only the fields shared with instructors;
     * "granted": every field, read-only, through an instructor's approved request.
     */
    scope: 'full' | 'instructor' | 'granted';
    entries: MedicalEntry[];
    canEdit: boolean;
    updatedAt: string | null;
    /** Full scope only. */
    updatedBy: string | null;
    /** Instructors only. */
    access: MedicalAccessState | null;
}

/** A request on the medical staff's Access Requests page. */
export interface MedicalAccessRow {
    id: number;
    candidate: { id: number; number: string; name: string; className: string | null };
    requestedBy: string;
    requestedAt: string | null;
    reason: string;
    status: { value: string; label: string; tone: StatusTone };
    expiresAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    revokedBy: string | null;
    can: { decide: boolean; revoke: boolean };
}
