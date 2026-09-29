/**
 * Question bank types shared by the question bank (Milestone 7) and the
 * examination builder (Milestone 8). Shapes mirror
 * App\Services\QuestionBank\QuestionPresenter; see
 * docs/question-bank-examination-contract.md.
 */

/** App\Enums\QuestionType */
export type QuestionTypeValue = 'multiple_choice' | 'true_false' | 'essay';

export interface QuestionTypeOption {
    value: QuestionTypeValue;
    label: string;
}

/** A choice as authorized staff see it. */
export interface StaffQuestionChoice {
    id: number;
    position: number;
    /** Display letter: A, B, C, ... */
    label: string;
    text: string;
    isCorrect: boolean;
}

/** A question as authorized staff see it (QuestionPresenter::staff). Includes the correct answer. */
export interface StaffQuestion {
    id: number;
    subject: { id: number; code: string; name: string };
    topic: { id: number; name: string } | null;
    type: QuestionTypeOption;
    prompt: string;
    /** Compact decimal string from the server, e.g. "1" or "2.5". */
    points: string;
    explanation: string | null;
    isActive: boolean;
    /** Part of a published examination: type, prompt, and choices can no longer change. */
    isLocked: boolean;
    /** In display order. Empty for essays. */
    choices: StaffQuestionChoice[];
}

/** A question as candidates see it (QuestionPresenter::forCandidate). Never contains answers. */
export interface CandidateQuestion {
    id: number;
    type: QuestionTypeOption;
    prompt: string;
    choices: { id: number; text: string }[];
}
