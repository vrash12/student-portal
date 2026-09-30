import type { QuestionTypeValue, StaffQuestion } from '@/types/question-bank';

/** One row of the multiple choice editor. */
export interface ChoiceDraft {
    /** Client-side key for React lists; never sent to the server. */
    key: string;
    text: string;
    is_correct: boolean;
}

/**
 * State of the question form. Choices and the true / false answer are kept
 * while the type changes, so switching back does not lose them; only the
 * fields of the selected type are sent (questionPayload).
 */
export interface QuestionFormData {
    subject_id: string;
    topic: string;
    type: QuestionTypeValue;
    prompt: string;
    points: string;
    explanation: string;
    choices: ChoiceDraft[];
    correct_answer: '' | 'true' | 'false';
}

/** Rows a new multiple choice question starts with (UI_UX_DESIGN.md §55: A–D). */
const DEFAULT_CHOICE_COUNT = 4;

const DEFAULT_POINTS = '1';

let lastChoiceKey = 0;

export function newChoice(text = '', isCorrect = false): ChoiceDraft {
    lastChoiceKey += 1;

    return { key: `choice-${lastChoiceKey}`, text, is_correct: isCorrect };
}

function blankChoices(): ChoiceDraft[] {
    return Array.from({ length: DEFAULT_CHOICE_COUNT }, () => newChoice());
}

/** A new multiple choice question worth 1 point. */
export function newQuestionFormData(subjectId: number | null): QuestionFormData {
    return {
        subject_id: subjectId === null ? '' : String(subjectId),
        topic: '',
        type: 'multiple_choice',
        prompt: '',
        points: DEFAULT_POINTS,
        explanation: '',
        choices: blankChoices(),
        correct_answer: '',
    };
}

/** The form state of a stored question. */
export function questionFormDataFrom(question: StaffQuestion): QuestionFormData {
    const firstChoice = question.choices[0];

    return {
        subject_id: String(question.subject.id),
        topic: question.topic?.name ?? '',
        type: question.type.value,
        prompt: question.prompt,
        points: question.points,
        explanation: question.explanation ?? '',
        choices:
            question.type.value === 'multiple_choice'
                ? question.choices.map((choice) => newChoice(choice.text, choice.isCorrect))
                : blankChoices(),
        correct_answer: question.type.value === 'true_false' && firstChoice !== undefined ? (firstChoice.isCorrect ? 'true' : 'false') : '',
    };
}

interface PayloadOptions {
    /** New questions only: the subject is fixed afterwards. */
    includeSubject: boolean;
    /** Type, prompt, and answers. Left out for locked questions, whose content cannot change. */
    includeContent: boolean;
}

/** What the server receives: only the fields of the selected type, without client-side keys. */
export function questionPayload(data: QuestionFormData, { includeSubject, includeContent }: PayloadOptions): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        topic: data.topic,
        points: data.points,
        explanation: data.explanation,
    };

    if (includeSubject) {
        payload.subject_id = data.subject_id;
    }

    if (includeContent) {
        payload.type = data.type;
        payload.prompt = data.prompt;

        if (data.type === 'multiple_choice') {
            payload.choices = data.choices.map(({ text, is_correct }) => ({ text, is_correct }));
        } else if (data.type === 'true_false') {
            payload.correct_answer = data.correct_answer;
        }
    }

    return payload;
}

/** "1 point", "2.5 points". */
export function pointsLabel(points: string): string {
    return points === '1' ? '1 point' : `${points} points`;
}
