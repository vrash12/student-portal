/*
 * Item analysis of an examination (ItemAnalysisService). Aggregate figures
 * only: the payload never contains candidate identity or individual answers.
 * Percentages are calculated by the server; the page only formats them.
 */
import type { ChartColumn } from '@/types/charts';
import type { QuestionMediaView, QuestionTypeOption } from '@/types/question-bank';

export type ItemAnalysisScope = 'latest' | 'all';

export type ItemAnalysisSort = 'missed' | 'order' | 'discrimination';

export type ItemAnalysisFlagCode = 'mostly_missed' | 'very_easy' | 'negative_discrimination' | 'distractor_preferred';

export interface ItemAnalysisFlag {
    code: ItemAnalysisFlagCode;
    label: string;
}

export interface ItemAnalysisChoice {
    id: number;
    /** Display letter in the analysis (A, B, C, ...). */
    label: string;
    text: string;
    isCorrect: boolean;
    /** Candidates who chose this answer. */
    count: number;
    /** Share of the times the question was delivered; null when never delivered. */
    percent: number | null;
}

export interface ItemAnalysisEssay {
    graded: number;
    ungraded: number;
    averageScore: number | null;
    maxPoints: number;
    averagePercent: number | null;
}

export interface ItemAnalysisQuestion {
    /** Examination question id. */
    id: number;
    /** Position in the examination; null if the question was removed after delivery. */
    position: number | null;
    type: QuestionTypeOption;
    prompt: string;
    media: QuestionMediaView[];
    points: number;
    /** Attempts in which this question was delivered (random subsets deliver fewer). */
    delivered: number;
    answered: number;
    unanswered: number;
    /** Share of deliveries left unanswered; null when never delivered. */
    unansweredPercent: number | null;
    /** Objective items only. */
    correct: number | null;
    /** Objective items only: correct answers out of times delivered. */
    percentCorrect: number | null;
    /** Objective items; empty for essays. */
    choices: ItemAnalysisChoice[];
    /** Essay items only. */
    essay: ItemAnalysisEssay | null;
    /** Percent correct (objective) or average percent of graded essays. */
    difficulty: number | null;
    /** Upper minus lower group proportion (−1 to 1); null when not available. */
    discrimination: number | null;
    flags: ItemAnalysisFlag[];
}

export interface ItemAnalysisSummary {
    submittedAttempts: number;
    candidates: number;
    /** Attempts with a final percentage (essays graded). */
    scoredAttempts: number;
    awaitingGrading: number;
    mean: number | null;
    median: number | null;
    highest: number | null;
    lowest: number | null;
    passingScore: number | null;
    passed: number | null;
    /** Scored attempts below the passing score; null without a passing score. */
    failed: number | null;
    passRate: number | null;
    /** Final percentages by range, lowest first; empty when no attempt has a final score. */
    scoreDistribution: ChartColumn[];
}

export interface ItemAnalysisDiscrimination {
    available: boolean;
    rankedAttempts: number;
    groupSize: number | null;
    minimum: number;
    reason: string | null;
}

export interface ItemAnalysis {
    scope: ItemAnalysisScope;
    sort: ItemAnalysisSort;
    summary: ItemAnalysisSummary;
    discrimination: ItemAnalysisDiscrimination;
    questions: ItemAnalysisQuestion[];
    /** Examination questions not delivered in any counted attempt. */
    undeliveredQuestions: number;
}

export interface ItemAnalysisExamination {
    id: number;
    title: string;
    kind: string;
    subject: string;
    classBatch: string;
}
