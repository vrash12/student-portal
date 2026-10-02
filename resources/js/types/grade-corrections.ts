import type { StatusTone } from '@/components/ui/status-badge';

export type GradeCorrectionStatusValue = 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface GradeCorrectionStatus {
    value: GradeCorrectionStatusValue;
    label: string;
    tone: StatusTone;
}

export interface IncidentTypeOption {
    value: string;
    label: string;
    description: string;
}

/** One grade correction request, as listed (GradeCorrectionPresenter::summary). */
export interface GradeCorrectionSummary {
    id: number;
    status: GradeCorrectionStatus;
    incidentType: { value: string; label: string };
    candidate: { id: number; number: string; name: string };
    assessment: { id: number; title: string; maxScore: string; subject: string; className: string };
    /** The score when the request was filed; null = no score. */
    currentScore: string | null;
    /** The score asked for; null = mark as missing. */
    proposedScore: string | null;
    requestedBy: string;
    requestedAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
}

/** A request with its full incident report (GradeCorrectionPresenter::details). */
export interface GradeCorrectionDetails extends GradeCorrectionSummary {
    currentComment: string | null;
    proposedComment: string | null;
    incidentDetails: string;
    decisionNote: string | null;
    /** The score now, to spot a change since the request was filed. */
    scoreNow: string | null;
    commentNow: string | null;
}

/** Correction data of a finalized assessment page. */
export interface AssessmentCorrections {
    /** Candidate id => id of the request waiting for approval. */
    pending: Record<string, number>;
    recent: GradeCorrectionSummary[];
    incidentTypes: IncidentTypeOption[];
}
