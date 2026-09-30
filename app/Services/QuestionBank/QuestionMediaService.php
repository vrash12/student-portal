<?php

namespace App\Services\QuestionBank;

use App\Enums\AuditAction;
use App\Models\Question;
use App\Models\QuestionMedia;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Images, audio, and video attached to questions.
 *
 * Media is question content: a locked question (part of a published
 * examination) cannot gain, change, or lose media, so every attempt keeps
 * showing the same files. Every change locks the question row first, the
 * same lock edits and publication take. Files are stored on the private
 * local disk; the audit log records the kind and size, never file names or
 * descriptions (they can reveal question content).
 */
final class QuestionMediaService
{
    public const MAX_PER_QUESTION = 4;

    public const DISK = 'local';

    public const LOCKED_MESSAGE = 'This question is part of a published examination, so its images and media can no longer change. Duplicate the question to make a changed version.';

    /**
     * Allowed types by detected MIME type: kind, stored extension, maximum size in kilobytes.
     * SVG is deliberately not allowed (it can contain scripts).
     *
     * @var array<string, array{kind: string, extension: string, maxKb: int}>
     */
    public const TYPES = [
        'image/jpeg' => ['kind' => QuestionMedia::KIND_IMAGE, 'extension' => 'jpg', 'maxKb' => 5120],
        'image/png' => ['kind' => QuestionMedia::KIND_IMAGE, 'extension' => 'png', 'maxKb' => 5120],
        'image/webp' => ['kind' => QuestionMedia::KIND_IMAGE, 'extension' => 'webp', 'maxKb' => 5120],
        'image/gif' => ['kind' => QuestionMedia::KIND_IMAGE, 'extension' => 'gif', 'maxKb' => 5120],
        'audio/mpeg' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'mp3', 'maxKb' => 15360],
        'audio/mp4' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'm4a', 'maxKb' => 15360],
        'audio/x-m4a' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'm4a', 'maxKb' => 15360],
        'audio/ogg' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'ogg', 'maxKb' => 15360],
        'audio/wav' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'wav', 'maxKb' => 15360],
        'audio/x-wav' => ['kind' => QuestionMedia::KIND_AUDIO, 'extension' => 'wav', 'maxKb' => 15360],
        'video/mp4' => ['kind' => QuestionMedia::KIND_VIDEO, 'extension' => 'mp4', 'maxKb' => 30720],
        'video/webm' => ['kind' => QuestionMedia::KIND_VIDEO, 'extension' => 'webm', 'maxKb' => 30720],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function add(Question $question, UploadedFile $file, string $description, User $actor): QuestionMedia
    {
        $mime = (string) $file->getMimeType();
        $type = self::TYPES[$mime] ?? null;
        if ($type === null) {
            throw ValidationException::withMessages(['file' => 'Upload a JPEG, PNG, WebP, or GIF image, an MP3, M4A, OGG, or WAV audio file, or an MP4 or WebM video.']);
        }
        if ($file->getSize() > $type['maxKb'] * 1024) {
            throw ValidationException::withMessages(['file' => sprintf('This %s is too large. The limit is %d MB.', $type['kind'], intdiv($type['maxKb'], 1024))]);
        }

        $dimensions = $type['kind'] === QuestionMedia::KIND_IMAGE ? @getimagesize($file->getRealPath()) : null;
        if ($type['kind'] === QuestionMedia::KIND_IMAGE && $dimensions === false) {
            throw ValidationException::withMessages(['file' => 'The image could not be read. Upload a valid image file.']);
        }

        $path = 'question-media/'.$question->id.'/'.Str::uuid().'.'.$type['extension'];
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($question, $file, $description, $actor, $type, $mime, $path, $dimensions): QuestionMedia {
                $locked = $this->lockEditable($question);
                $count = $locked->media()->count();
                if ($count >= self::MAX_PER_QUESTION) {
                    throw ValidationException::withMessages(['file' => sprintf('A question can have at most %d images or media files.', self::MAX_PER_QUESTION)]);
                }

                $media = new QuestionMedia;
                $media->question_id = $locked->id;
                $media->position = ((int) $locked->media()->max('position')) + 1;
                $media->kind = $type['kind'];
                $media->path = $path;
                $media->original_name = Str::limit($file->getClientOriginalName(), 250, '');
                $media->mime_type = $mime;
                $media->size_bytes = (int) $file->getSize();
                $media->width = is_array($dimensions) ? min(65535, (int) $dimensions[0]) : null;
                $media->height = is_array($dimensions) ? min(65535, (int) $dimensions[1]) : null;
                $media->description = $description;
                $media->created_by = $actor->id;
                $media->save();

                $this->touch($locked, $actor);
                $this->audit->record(AuditAction::QuestionUpdated, $locked,
                    oldValues: ['media_count' => $count],
                    newValues: ['media_count' => $count + 1, 'changed' => ['media'], 'media_added' => ['kind' => $media->kind, 'size_bytes' => $media->size_bytes]],
                    actor: $actor);

                return $media;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * @return bool whether the description changed
     *
     * @throws ValidationException
     */
    public function describe(QuestionMedia $media, string $description, User $actor): bool
    {
        return DB::transaction(function () use ($media, $description, $actor): bool {
            $locked = $this->lockEditable($media->question);
            $row = QuestionMedia::query()->whereKey($media->id)->where('question_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($row->description === $description) {
                return false;
            }

            $row->description = $description;
            $row->save();
            $this->touch($locked, $actor);
            $this->audit->record(AuditAction::QuestionUpdated, $locked, newValues: ['changed' => ['media_description']], actor: $actor);

            return true;
        });
    }

    /**
     * @throws ValidationException
     */
    public function remove(QuestionMedia $media, User $actor): void
    {
        DB::transaction(function () use ($media, $actor): void {
            $locked = $this->lockEditable($media->question);
            $row = QuestionMedia::query()->whereKey($media->id)->where('question_id', $locked->id)->lockForUpdate()->firstOrFail();
            $count = $locked->media()->count();
            $path = $row->path;
            $kind = $row->kind;
            $row->delete();

            // Keep positions contiguous (1..n).
            $locked->media()->get()->each(function (QuestionMedia $item, int $index): void {
                if ($item->position !== $index + 1) {
                    $item->position = $index + 1;
                    $item->save();
                }
            });

            $this->touch($locked, $actor);
            $this->audit->record(AuditAction::QuestionUpdated, $locked,
                oldValues: ['media_count' => $count],
                newValues: ['media_count' => $count - 1, 'changed' => ['media'], 'media_removed' => ['kind' => $kind]],
                actor: $actor);

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        });
    }

    /**
     * Copies every media file of $source to $copy (used when duplicating a
     * question). Call inside the duplication transaction; copied files are
     * removed again if it rolls back.
     */
    public function copyAll(Question $source, Question $copy, User $actor): void
    {
        $disk = Storage::disk(self::DISK);
        $copied = [];

        try {
            foreach ($source->media()->get() as $original) {
                $path = 'question-media/'.$copy->id.'/'.Str::uuid().'.'.pathinfo($original->path, PATHINFO_EXTENSION);
                if (! $disk->copy($original->path, $path)) {
                    throw new \RuntimeException('A media file of the question could not be copied.');
                }
                $copied[] = $path;

                $media = $original->replicate(['path', 'question_id', 'created_by']);
                $media->question_id = $copy->id;
                $media->path = $path;
                $media->created_by = $actor->id;
                $media->save();
            }
        } catch (Throwable $exception) {
            $disk->delete($copied);

            throw $exception;
        }

        DB::afterRollBack(fn () => $disk->delete($copied));
    }

    public function absolutePath(QuestionMedia $media): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($media->path) ? $disk->path($media->path) : null;
    }

    /**
     * @throws ValidationException
     */
    private function lockEditable(Question $question): Question
    {
        // Serialized with edits and publication, which lock the same row.
        $locked = Question::query()->lockForUpdate()->findOrFail($question->getKey());
        if ($locked->isLocked()) {
            throw ValidationException::withMessages(['file' => self::LOCKED_MESSAGE]);
        }

        return $locked;
    }

    private function touch(Question $question, User $actor): void
    {
        $question->updater()->associate($actor);
        $question->updated_at = $question->freshTimestamp();
        $question->save();
    }
}
