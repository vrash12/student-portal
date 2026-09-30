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
    photoUrl: string | null;
    status: StatusValue;
    classBatch: { id: number; name: string; period: string; periodId: number } | null;
    account: { username: string; isActive: boolean; lastLoginAt: string | null } | null;
    createdAt: string | null;
    updatedAt: string | null;
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
