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
    /** Optional image of the choice (multiple choice only). */
    image: StaffQuestionMedia | null;
}

/** An image, audio clip, or video shown with a question. */
export interface QuestionMediaView {
    id: number;
    kind: 'image' | 'audio' | 'video';
    /** Alternative text (images) or caption (audio, video). */
    description: string;
    mimeType: string;
    width: number | null;
    height: number | null;
}

/** Staff view of a media file, with its authorized URL. */
export interface StaffQuestionMedia extends QuestionMediaView {
    url: string;
    originalName: string;
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
    /** Shown below the prompt, in display order. */
    media: StaffQuestionMedia[];
}

/** A question as candidates see it (QuestionPresenter::forCandidate). Never contains answers. */
export interface CandidateQuestion {
    id: number;
    type: QuestionTypeOption;
    prompt: string;
    choices: { id: number; text: string; image: QuestionMediaView | null }[];
    /** URLs are built per attempt: /portal/attempts/{attempt}/media/{id}. */
    media: QuestionMediaView[];
}

/** A question in a staff list (QuestionPresenter::summary). Never contains choices, answers, or the explanation. */
export interface QuestionSummary {
    id: number;
    subject: { id: number; code: string; name: string };
    topic: { id: number; name: string } | null;
    type: QuestionTypeOption;
    /** About 200 characters of the prompt on one line. */
    excerpt: string;
    /** Compact decimal string from the server, e.g. "1" or "2.5". */
    points: string;
    isActive: boolean;
    /** Part of a published examination. */
    isLocked: boolean;
}

/** A subject the user teaches, as offered in question bank selects. */
export interface QuestionBankSubject {
    id: number;
    code: string;
    name: string;
}

/**
 * Input limits of the question form, sent by the server (App\Enums\QuestionType,
 * App\Services\QuestionBank\QuestionBankService). Lengths are in characters.
 */
export interface QuestionLimits {
    minChoices: number;
    maxChoices: number;
    promptLength: number;
    choiceLength: number;
    topicLength: number;
    explanationLength: number;
}
