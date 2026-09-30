import { CircleCheck } from 'lucide-react';
import { QuestionMediaList } from '@/components/question-bank/question-media';
import { cn } from '@/lib/cn';
import type { StaffQuestion } from '@/types/question-bank';

interface QuestionPreviewProps {
    question: StaffQuestion;
    /** Mark the correct choice. Staff pages only; never render this for candidates. */
    showAnswer?: boolean;
    /** Points to show, e.g. an examination's own points; defaults to the question's points. */
    points?: string;
}

/**
 * Staff preview of a question as it will be presented: type, points, prompt
 * (line breaks kept), and lettered choices with the correct one marked by an
 * icon and text, never by color alone. Shared by the question bank and the
 * examination builder.
 */
export function QuestionPreview({ question, showAnswer = true, points }: QuestionPreviewProps) {
    const shownPoints = points ?? question.points;

    return (
        <div className="space-y-4">
            <p className="text-sm text-ink-muted">
                <span className="font-medium text-ink">{question.type.label}</span>
                <span aria-hidden="true"> · </span>
                {pointsLabel(shownPoints)}
            </p>

            <p className="whitespace-pre-wrap break-words text-base text-ink">{question.prompt}</p>

            <QuestionMediaList media={question.media} urlFor={(media) => media.url} />

            {question.choices.length > 0 ? (
                <ol className="space-y-2" aria-label="Answer choices">
                    {question.choices.map((choice) => {
                        const isShownCorrect = showAnswer && choice.isCorrect;

                        return (
                            <li
                                key={choice.id}
                                className={cn(
                                    'flex items-start gap-3 rounded-md border px-3 py-2.5',
                                    isShownCorrect ? 'border-success-border bg-success-bg' : 'border-line bg-surface',
                                )}
                            >
                                <span className="font-semibold text-ink" aria-hidden="true">
                                    {choice.label}.
                                </span>
                                <span className="min-w-0 flex-1 whitespace-pre-wrap break-words text-ink">
                                    <span className="sr-only">Choice {choice.label}: </span>
                                    {choice.text}
                                </span>
                                {isShownCorrect && (
                                    <span className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-success-fg">
                                        <CircleCheck className="size-4" aria-hidden="true" />
                                        Correct answer
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ol>
            ) : (
                <p className="text-sm text-ink-muted">Essay question: candidates write their answer, and an instructor grades it.</p>
            )}
        </div>
    );
}

function pointsLabel(points: string): string {
    return points === '1' ? '1 point' : `${points} points`;
}
