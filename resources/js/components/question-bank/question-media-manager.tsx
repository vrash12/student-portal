import { useForm } from '@inertiajs/react';
import { FileAudio, FileVideo, ImagePlus } from 'lucide-react';
import { useId, useRef, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmAction } from '@/components/ui/confirm-action';
import { FormField, SelectInput, TextArea } from '@/components/ui/form-field';
import { Panel } from '@/components/ui/panel';
import { routes } from '@/lib/routes';
import type { StaffQuestion, StaffQuestionMedia } from '@/types/question-bank';

export const MAX_MEDIA_PER_QUESTION = 4;

const ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,audio/mpeg,audio/mp4,audio/x-m4a,audio/ogg,audio/wav,video/mp4,video/webm';

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';

/**
 * Images, audio, and video of a question (question edit page). Each file is
 * uploaded on its own, with a required description (image alternative text,
 * or the caption of audio and video). Locked questions show their media
 * read-only: it is part of the published content.
 */
export function QuestionMediaManager({ question }: { question: StaffQuestion }) {
    const count = question.media.length;
    const isMultipleChoice = question.type.value === 'multiple_choice';
    const choiceImages = question.choices.flatMap((choice) => (choice.image === null ? [] : [{ choice, media: choice.image }]));

    return (
        <Panel
            title="Images and Media"
            description={`Shown to candidates below the question. Up to ${MAX_MEDIA_PER_QUESTION} files: images (JPEG, PNG, WebP, GIF, 5 MB), audio (MP3, M4A, OGG, WAV, 15 MB), or video (MP4, WebM, 30 MB).${isMultipleChoice ? ' Each answer choice can also show one image.' : ''}`}
            className="mt-6"
        >
            {question.isLocked && (
                <p className="mb-4 rounded-md border border-info-border bg-info-bg p-3 text-sm text-info-fg">
                    This question is part of a published examination, so its images and media can no longer change. Duplicate the question to make a changed version.
                </p>
            )}

            {count === 0 ? (
                <p className="text-sm text-ink-muted">No images or media yet.</p>
            ) : (
                <ul className="space-y-4">
                    {question.media.map((media, index) => (
                        <MediaItem key={media.id} question={question} media={media} heading={`${index + 1}. ${kindLabel(media.kind)}`} />
                    ))}
                </ul>
            )}

            {choiceImages.length > 0 && (
                <>
                    <h3 className="mt-6 mb-3 text-sm font-semibold text-ink">Answer Choice Images</h3>
                    <ul className="space-y-4">
                        {choiceImages.map(({ choice, media }) => (
                            <MediaItem key={media.id} question={question} media={media} heading={`Choice ${choice.label} image`} />
                        ))}
                    </ul>
                </>
            )}

            {!question.isLocked && <UploadForm question={question} questionMediaFull={count >= MAX_MEDIA_PER_QUESTION} />}
        </Panel>
    );
}

function MediaItem({ question, media, heading }: { question: StaffQuestion; media: StaffQuestionMedia; heading: string }) {
    const form = useForm({ description: media.description });
    const fieldId = useId();

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.processing) {
            form.put(routes.questionBank.media.update(question.id, media.id), { preserveScroll: true });
        }
    };

    return (
        <li className="flex flex-col gap-4 rounded-lg border border-line p-3 sm:flex-row">
            <div className="flex w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-surface-muted sm:w-40">
                {media.kind === 'image' ? (
                    <img src={media.url} alt="" className="max-h-32 w-auto object-contain" loading="lazy" />
                ) : media.kind === 'audio' ? (
                    <FileAudio className="size-10 text-ink-muted" aria-hidden="true" />
                ) : (
                    <FileVideo className="size-10 text-ink-muted" aria-hidden="true" />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-ink">
                    {heading} <span className="font-normal text-ink-muted">· {media.originalName}</span>
                </p>
                {question.isLocked ? (
                    <p className="mt-2 text-sm text-ink">{media.description}</p>
                ) : (
                    <form onSubmit={save} className="mt-2 space-y-2">
                        <FormField label={media.kind === 'image' ? 'Alternative text' : 'Caption'} error={form.errors.description} required>
                            <TextArea id={fieldId} rows={2} maxLength={500} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                        </FormField>
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" variant="secondary" loading={form.processing} disabled={!form.isDirty}>
                                Save Description
                            </Button>
                            <ConfirmAction
                                method="delete"
                                href={routes.questionBank.media.destroy(question.id, media.id)}
                                title={`Remove ${kindLabel(media.kind).toLowerCase()}?`}
                                description="Candidates will no longer see it with this question. Examinations that are already published are not affected."
                                confirmLabel="Remove"
                                variant="danger"
                            >
                                Remove
                            </ConfirmAction>
                        </div>
                    </form>
                )}
            </div>
        </li>
    );
}

function UploadForm({ question, questionMediaFull }: { question: StaffQuestion; questionMediaFull: boolean }) {
    // Choices that can still get an image (multiple choice only, one image each).
    const openChoices = question.type.value === 'multiple_choice' ? question.choices.filter((choice) => choice.image === null) : [];
    const form = useForm<{ file: File | null; description: string; choice: string }>({
        file: null,
        description: '',
        choice: questionMediaFull && openChoices[0] ? String(openChoices[0].position) : '',
    });
    const input = useRef<HTMLInputElement>(null);
    const forChoice = form.data.choice !== '';

    if (questionMediaFull && openChoices.length === 0) {
        return <p className="mt-4 text-sm text-ink-muted">This question has the maximum of {MAX_MEDIA_PER_QUESTION} files. Remove one to add another.</p>;
    }

    const upload = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.processing) {
            return;
        }

        form.post(routes.questionBank.media.store(question.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (input.current) {
                    input.current.value = '';
                }
            },
        });
    };

    return (
        <form onSubmit={upload} className="mt-6 space-y-4 border-t border-line pt-4">
            <h3 className="text-sm font-semibold text-ink">Add an Image or Media File</h3>
            {openChoices.length > 0 && (
                <FormField label="Show with" error={(form.errors as Record<string, string | undefined>).target ?? form.errors.choice} hint="Answer choices can show one image each.">
                    <SelectInput value={form.data.choice} onChange={(event) => form.setData('choice', event.target.value)}>
                        {!questionMediaFull && <option value="">The question</option>}
                        {openChoices.map((choice) => (
                            <option key={choice.id} value={String(choice.position)}>
                                Choice {choice.label}: {choice.text.length > 40 ? `${choice.text.slice(0, 40)}…` : choice.text}
                            </option>
                        ))}
                    </SelectInput>
                </FormField>
            )}
            <FormField label={forChoice ? 'Image' : 'File'} error={form.errors.file} required>
                <input
                    ref={input}
                    type="file"
                    accept={forChoice ? IMAGE_ACCEPT : ACCEPT}
                    className="block w-full text-sm text-ink file:mr-3 file:min-h-10 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-3 file:text-sm file:font-medium pointer-coarse:file:min-h-11"
                    onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)}
                />
            </FormField>
            <FormField
                label="Description"
                hint={forChoice ? 'Describe the image for candidates who cannot see it. Do not reveal whether this choice is correct.' : 'Describe what the file shows or says, for candidates who cannot see or hear it. Do not include the answer.'}
                error={form.errors.description}
                required
            >
                <TextArea rows={2} maxLength={500} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
            </FormField>
            {form.progress && <progress className="w-full" value={form.progress.percentage ?? 0} max={100} aria-label="Upload progress" />}
            <Button type="submit" icon={<ImagePlus className="size-4" aria-hidden="true" />} loading={form.processing} disabled={form.data.file === null}>
                Upload
            </Button>
        </form>
    );
}

function kindLabel(kind: StaffQuestionMedia['kind']): string {
    return kind === 'image' ? 'Image' : kind === 'audio' ? 'Audio' : 'Video';
}
