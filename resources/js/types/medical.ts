import type { StatusTone } from '@/components/ui/status-badge';

export type MedicalFieldTypeValue = 'text' | 'long_text' | 'number' | 'choice' | 'date' | 'yes_no';

export interface MedicalFieldTypeOption {
    value: MedicalFieldTypeValue;
    label: string;
    description: string;
}

/** A medical record field as defined by administrators (MedicalRecordPresenter::field). */
export interface MedicalFieldDefinition {
    id: number;
    name: string;
    /** Heading the field is listed under; null = no section. */
    section: string | null;
    type: { value: MedicalFieldTypeValue; label: string };
    /** Choice fields only. */
    options: string[];
    /** Number fields only, e.g. "cm". */
    unit: string | null;
    helpText: string | null;
    sortOrder: number;
    visibleToCandidate: boolean;
    isActive: boolean;
}

/** One field of a candidate's record with its value (null = not recorded). */
export interface MedicalEntry {
    fieldId: number;
    name: string;
    section: string | null;
    type: MedicalFieldTypeValue;
    unit: string | null;
    helpText: string | null;
    visibleToCandidate: boolean;
    value: string | null;
}

export type MedicalDocumentStatusValue = 'submitted' | 'accepted' | 'returned';

/**
 * A medical document a candidate uploaded (MedicalRecordPresenter::document).
 * Instructors of the candidate's class get `protectedUrl` only (view in the
 * protected viewer); medical staff and the candidate get `fileUrl`/`downloadUrl`.
 */
export interface MedicalDocument {
    id: number;
    title: string;
    category: { value: string; label: string };
    documentDate: string | null;
    notes: string | null;
    fileType: 'pdf' | 'image';
    sizeBytes: number;
    status: { value: MedicalDocumentStatusValue; label: string; tone: StatusTone };
    uploadedAt: string | null;
    reviewedAt: string | null;
    reviewedBy: string | null;
    /** Accepting note, or the reason a document was returned. */
    reviewNote: string | null;
    fileUrl: string | null;
    downloadUrl: string | null;
    protectedUrl: string | null;
    /** Instructors: where the protected viewer reports a Print Screen press. */
    printScreenUrl: string | null;
    /** Instructors: their download request for this document; null for everyone else. */
    download: MedicalDownloadState | null;
    can: { review: boolean; withdraw: boolean };
}

/** An instructor's request for a copy of one document (MedicalRecordPresenter::downloadState). */
export interface MedicalDownloadState {
    /** The latest request, if any. */
    request: {
        id: number;
        status: { value: string; label: string; tone: StatusTone };
        expiresAt: string | null;
        decidedBy: string | null;
        decisionNote: string | null;
        downloadCount: number;
    } | null;
    /** Set while an approval is active. */
    fileUrl: string | null;
    canRequest: boolean;
    canCancel: boolean;
}

/** A download request on the medical staff's page. */
export interface MedicalDownloadRow {
    id: number;
    candidate: { id: number; number: string; name: string; className: string | null };
    document: { id: number; title: string; category: string; fileUrl: string };
    requestedBy: string;
    requestedAt: string | null;
    reason: string;
    status: { value: string; label: string; tone: StatusTone };
    expiresAt: string | null;
    decidedBy: string | null;
    decisionNote: string | null;
    revokedBy: string | null;
    downloadCount: number;
    lastDownloadedAt: string | null;
    can: { decide: boolean; revoke: boolean };
}

/** A document on the medical staff's review page. */
export interface MedicalDocumentRow extends MedicalDocument {
    candidate: { id: number; number: string; name: string; className: string | null };
}

/** The medical panel of the staff candidate profile. */
export interface ProfileMedical {
    /** "full": every field (medical staff); "granted": every field, view only (instructors of the candidate's class). */
    scope: 'full' | 'granted';
    entries: MedicalEntry[];
    canEdit: boolean;
    updatedAt: string | null;
    /** Full scope only. */
    updatedBy: string | null;
    /** Uploaded documents: every one for medical staff; not returned ones, view only, for instructors of the class. */
    documents: MedicalDocument[];
    /** Medical staff only: documents waiting for review. */
    waitingDocuments: number;
}

