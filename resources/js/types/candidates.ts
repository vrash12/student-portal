import type { StatusValue } from '@/types/grading';

export interface CandidateInformation {
    id: number;
    candidateNumber: string;
    firstName: string;
    middleName: string | null;
    lastName: string;
    suffix: string | null;
    name: string;
    trainingGroup: string | null;
    /** Free-text unit assignments; null when not assigned. */
    company: string | null;
    platoon: string | null;
    photoUrl: string | null;
    /** The candidate's QR code image (attendance and identification); always present. */
    qrCodeUrl: string;
    /** The QR code card as a PDF file (Save as PDF). */
    qrCardUrl: string;
    status: StatusValue;
    classBatch: { id: number; name: string; period: string; periodId: number } | null;
    account: { username: string; isActive: boolean; lastLoginAt: string | null } | null;
    createdAt: string | null;
    updatedAt: string | null;
}

/**
 * Company and platoon names already in use (App\Support\CandidateGroups),
 * naturally sorted. Used for list filters and as typing suggestions.
 */
export interface CandidateGroupOptions {
    companyOptions: string[];
    platoonOptions: string[];
}

export interface CandidateExaminationResult {
    id: number;
    title: string;
    kind: string;
    subject: string;
    className: string;
    attemptNumber: number;
    status: string;
    submittedAt: string | null;
    resultLabel: string;
    score: string | null;
    maxScore: string | null;
    percentage: number | null;
    passed: boolean | null;
}

export interface CandidateAssessmentResult {
    id: number;
    title: string;
    subject: string;
    className: string;
    category: string;
    date: string | null;
    score: string | null;
    maxScore: string;
    percentage: number | null;
}

/** One education entry of a candidate (CandidateBackgroundPresenter). */
export interface CandidateEducationEntry {
    level: string;
    levelLabel: string;
    degree: string;
    school: string;
    yearGraduated: number | null;
    honors: string | null;
}

/**
 * The candidate's background record (owner request, 2026-10-03). "full":
 * administrators and the candidate; "service": instructors of the class,
 * without personal or contact details (personal is null).
 */
export interface CandidateBackground {
    scope: 'full' | 'service';
    personal: {
        /** Y-m-d. */
        dateOfBirth: string | null;
        age: number | null;
        placeOfBirth: string | null;
        sex: string | null;
        civilStatus: string | null;
        homeAddress: string | null;
        mobileNumber: string | null;
        personalEmail: string | null;
        emergencyContact: { name: string | null; relationship: string | null; phone: string | null };
    } | null;
    service: { eligibility: string | null; priorService: string | null; previousOccupation: string | null };
    education: CandidateEducationEntry[];
}
