<?php

namespace App\Services\Medical;

use App\Enums\AuditAction;
use App\Enums\MedicalDocumentStatus;
use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\CandidateMedicalDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Medical documents candidates upload (owner request, 2026-10-02):
 * certificates, check-up findings, laboratory results and similar files.
 *
 * A candidate uploads a document for their own record; it waits for review
 * until medical staff accept it or return it with a reason (the candidate
 * then uploads a corrected file). The candidate may withdraw an upload only
 * while it waits. Files are stored on the private local disk and their type
 * is checked from the contents. The audit log records the category and size
 * only: never titles, file names or notes, which can reveal health details.
 */
final class MedicalDocumentService
{
    public const DISK = 'local';

    public const MAX_KB = 10240;

    public const MAX_PER_CANDIDATE = 100;

    public const MAX_IMAGE_SIDE = 10000;

    public const MAX_IMAGE_PIXELS = 50_000_000;

    /**
     * Allowed types by detected MIME type and their stored extension.
     * SVG and other formats are not allowed.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{category: string, title: string, document_date: ?string, notes: ?string}  $details
     *
     * @throws ValidationException
     */
    public function upload(Candidate $candidate, UploadedFile $file, array $details, User $actor): CandidateMedicalDocument
    {
        $mime = (string) $file->getMimeType();
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF file or a photo (JPEG, PNG or WebP).']);
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['file' => 'This file is too large. The limit is '.intdiv(self::MAX_KB, 1024).' MB.']);
        }
        $this->checkContents($file, $mime);

        $path = 'medical-documents/'.$candidate->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        try {
            return DB::transaction(function () use ($candidate, $file, $details, $actor, $mime, $path): CandidateMedicalDocument {
                // Serialises uploads of one candidate so the limit holds.
                Candidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if (CandidateMedicalDocument::query()->where('candidate_id', $candidate->id)->count() >= self::MAX_PER_CANDIDATE) {
                    throw ValidationException::withMessages(['file' => 'You have reached the limit of '.self::MAX_PER_CANDIDATE.' medical documents. Ask the medical staff for help.']);
                }

                $document = new CandidateMedicalDocument;
                $document->candidate()->associate($candidate);
                $document->category = $details['category'];
                $document->title = $details['title'];
                $document->document_date = $details['document_date'];
                $document->notes = $details['notes'];
                $document->path = $path;
                $document->original_name = Str::limit($file->getClientOriginalName(), 250, '');
                $document->mime_type = $mime;
                $document->size_bytes = (int) $file->getSize();
                $document->status = MedicalDocumentStatus::Submitted;
                $document->uploader()->associate($actor);
                $document->save();

                $this->audit->record(AuditAction::MedicalDocumentUploaded, $document, newValues: $this->summary($document, $candidate), actor: $actor);

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * The candidate removes an upload that is still waiting for review.
     *
     * @throws ValidationException
     */
    public function withdraw(CandidateMedicalDocument $document, User $actor): void
    {
        $path = DB::transaction(function () use ($document, $actor): string {
            $locked = $this->lock($document);
            if (! $locked->isWaiting()) {
                throw ValidationException::withMessages(['document' => 'This document was already reviewed, so it stays on your record. Ask the medical staff if it is wrong.']);
            }

            $this->audit->record(AuditAction::MedicalDocumentWithdrawn, $locked, oldValues: $this->summary($locked, $locked->candidate), actor: $actor);
            $path = $locked->path;
            $locked->delete();

            return $path;
        });

        // Only after the row is gone, so a failed transaction never loses the file.
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * @throws ValidationException
     */
    public function accept(CandidateMedicalDocument $document, ?string $note, User $reviewer): void
    {
        $this->review($document, MedicalDocumentStatus::Accepted, $this->clean($note), $reviewer);
    }

    /**
     * Returns an upload to the candidate (unreadable, wrong file, incomplete);
     * the reason is shown to the candidate.
     *
     * @throws ValidationException
     */
    public function return(CandidateMedicalDocument $document, string $reason, User $reviewer): void
    {
        $reason = $this->clean($reason);
        if ($reason === null || mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Tell the candidate why the document is returned (at least 5 characters).']);
        }

        $this->review($document, MedicalDocumentStatus::Returned, $reason, $reviewer);
    }

    /**
     * Records that an instructor opened a document through approved access.
     */
    public function recordView(CandidateMedicalDocument $document, int $accessRequestId, User $viewer): void
    {
        $this->audit->record(AuditAction::MedicalDocumentViewed, $document, newValues: [
            ...$this->summary($document, $document->candidate),
            'access_request' => $accessRequestId,
        ], actor: $viewer);
    }

    /**
     * Records a Print Screen press reported by the protected viewer of an
     * instructor with approved access (a browser cannot stop screenshots;
     * this keeps a trace of the attempt).
     */
    public function recordPrintScreen(CandidateMedicalDocument $document, int $accessRequestId, User $viewer): void
    {
        $this->audit->record(AuditAction::MedicalDocumentPrintScreen, $document, newValues: [
            ...$this->summary($document, $document->candidate),
            'access_request' => $accessRequestId,
        ], actor: $viewer);
    }

    /** Absolute path of the stored file, or null when it is missing. */
    public function absolutePath(CandidateMedicalDocument $document): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($document->path) ? $disk->path($document->path) : null;
    }

    /**
     * @throws ValidationException
     */
    private function review(CandidateMedicalDocument $document, MedicalDocumentStatus $status, ?string $note, User $reviewer): void
    {
        DB::transaction(function () use ($document, $status, $note, $reviewer): void {
            $locked = $this->lock($document);
            if (! $locked->isWaiting()) {
                throw ValidationException::withMessages(['document' => 'This document was already reviewed.']);
            }
            if (! $reviewer->hasPermission(Permission::ManageMedical) || ! $reviewer->hasPermission(Permission::ViewMedical)) {
                throw ValidationException::withMessages(['document' => 'Only medical staff review medical documents.']);
            }
            if ((int) $locked->uploaded_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['document' => 'You cannot review a document you uploaded.']);
            }

            $locked->status = $status;
            $locked->reviewer()->associate($reviewer);
            $locked->reviewed_at = now();
            $locked->review_note = $note;
            $locked->save();

            $this->audit->record(
                $status === MedicalDocumentStatus::Accepted ? AuditAction::MedicalDocumentAccepted : AuditAction::MedicalDocumentReturned,
                $locked,
                newValues: $this->summary($locked, $locked->candidate),
                actor: $reviewer,
            );
        });
    }

    /**
     * Rejects files whose contents do not match a readable PDF or image.
     *
     * @throws ValidationException
     */
    private function checkContents(UploadedFile $file, string $mime): void
    {
        if ($mime === 'application/pdf') {
            $head = (string) @file_get_contents($file->getRealPath(), false, null, 0, 1024);
            if (! str_contains($head, '%PDF-')) {
                throw ValidationException::withMessages(['file' => 'The PDF file could not be read. Save it again as a PDF and upload it.']);
            }

            return;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            throw ValidationException::withMessages(['file' => 'The photo could not be read. Upload a valid JPEG, PNG or WebP image.']);
        }
        if ($dimensions[0] > self::MAX_IMAGE_SIDE || $dimensions[1] > self::MAX_IMAGE_SIDE || $dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS) {
            throw ValidationException::withMessages(['file' => sprintf('This photo is %d × %d pixels, which is too large. Take it again at a lower resolution, or resize it.', $dimensions[0], $dimensions[1])]);
        }
    }

    private function lock(CandidateMedicalDocument $document): CandidateMedicalDocument
    {
        return CandidateMedicalDocument::query()->with('candidate:id,candidate_number')->lockForUpdate()->findOrFail($document->getKey());
    }

    /**
     * What the audit log may hold: no title, file name or notes.
     *
     * @return array<string, mixed>
     */
    private function summary(CandidateMedicalDocument $document, ?Candidate $candidate): array
    {
        return [
            'candidate' => $candidate?->candidate_number,
            'category' => $document->category->value,
            'status' => $document->status->value,
            'size_bytes' => $document->size_bytes,
        ];
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
