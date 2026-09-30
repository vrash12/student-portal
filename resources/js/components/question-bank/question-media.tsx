import type { QuestionMediaView } from '@/types/question-bank';

interface QuestionMediaListProps<T extends QuestionMediaView> {
    media: T[];
    /** URL of a media file; staff and candidates use different authorized routes. */
    urlFor: (media: T) => string;
    className?: string;
}

/**
 * Images, audio, and video of a question, below its prompt. Images keep
 * their proportions and fit the screen width (tablet friendly); audio and
 * video use the browser's own accessible controls. The description is the
 * image's alternative text and the caption of audio and video.
 */
export function QuestionMediaList<T extends QuestionMediaView>({ media, urlFor, className }: QuestionMediaListProps<T>) {
    if (media.length === 0) {
        return null;
    }

    return (
        <div className={className ?? 'space-y-4'}>
            {media.map((item) => (
                <figure key={item.id} className="rounded-lg border border-line bg-surface p-2">
                    {item.kind === 'image' && (
                        <img
                            src={urlFor(item)}
                            alt={item.description}
                            width={item.width ?? undefined}
                            height={item.height ?? undefined}
                            loading="lazy"
                            className="mx-auto h-auto max-h-[28rem] w-auto max-w-full rounded-md object-contain"
                        />
                    )}
                    {item.kind === 'audio' && (
                        <audio controls preload="metadata" className="w-full" aria-label={item.description}>
                            <source src={urlFor(item)} type={item.mimeType} />
                            Your browser cannot play this audio.
                        </audio>
                    )}
                    {item.kind === 'video' && (
                        <video controls playsInline preload="metadata" className="mx-auto max-h-[28rem] w-full rounded-md bg-black" aria-label={item.description}>
                            <source src={urlFor(item)} type={item.mimeType} />
                            Your browser cannot play this video.
                        </video>
                    )}
                    {item.kind !== 'image' && <figcaption className="mt-2 px-1 text-sm text-ink-muted">{item.description}</figcaption>}
                </figure>
            ))}
        </div>
    );
}
