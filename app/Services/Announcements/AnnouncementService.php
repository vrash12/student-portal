<?php

namespace App\Services\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AuditAction;
use App\Models\Announcement;
use App\Models\Campus;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts, changes and withdraws notices to candidates (owner request,
 * 2026-10-06). The audience is fixed when the notice is posted; to reach
 * other candidates, withdraw it and post a new one. Every change is in the
 * audit log with the previous and new values (AGENTS.md §35).
 */
final class AnnouncementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Posts a notice. The caller has checked that the actor may post to the
     * audience (AnnouncementScope::allowsTarget). A start in the past means now.
     *
     * @param  array{title: string, body: string, is_important: bool, publishes_at: ?CarbonImmutable, expires_at: ?CarbonImmutable}  $details
     */
    public function post(User $actor, AnnouncementAudience $audience, ?int $campusId, ?int $classBatchId, array $details): Announcement
    {
        return DB::transaction(function () use ($actor, $audience, $campusId, $classBatchId, $details): Announcement {
            $now = CarbonImmutable::now();
            $publishesAt = $details['publishes_at'] === null || $details['publishes_at']->lt($now) ? $now : $details['publishes_at'];
            $this->ensureEndsLater($publishesAt, $details['expires_at']);
            if ($details['expires_at'] !== null && $details['expires_at']->lte($now)) {
                throw ValidationException::withMessages(['expires_at' => 'Choose an end in the future.']);
            }

            $announcement = new Announcement([
                'title' => $details['title'],
                'body' => $details['body'],
                'is_important' => $details['is_important'],
                'publishes_at' => $publishesAt,
                'expires_at' => $details['expires_at'],
            ]);
            $announcement->audience = $audience;
            // A class notice carries the class's campus (composite foreign key).
            $announcement->class_batch_id = $classBatchId;
            $announcement->campus_id = $classBatchId !== null
                ? (int) ClassBatch::query()->whereKey($classBatchId)->valueOrFail('campus_id')
                : $campusId;
            $announcement->created_by = $actor->id;
            $announcement->save();

            $this->audit->record(AuditAction::AnnouncementPosted, $announcement, newValues: $this->snapshot($announcement), actor: $actor);

            return $announcement;
        });
    }

    /**
     * Changes the text, importance and dates of a notice that is not withdrawn.
     *
     * @param  array{title: string, body: string, is_important: bool, publishes_at: ?CarbonImmutable, expires_at: ?CarbonImmutable}  $details
     */
    public function update(Announcement $announcement, array $details, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $details, $actor): Announcement {
            $locked = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ($locked->withdrawn_at !== null) {
                throw ValidationException::withMessages(['announcement' => 'This notice was withdrawn and can no longer be changed.']);
            }

            $publishesAt = $details['publishes_at'] ?? CarbonImmutable::instance($locked->publishes_at);
            $this->ensureEndsLater($publishesAt, $details['expires_at']);

            $before = $this->snapshot($locked);
            $locked->fill([
                'title' => $details['title'],
                'body' => $details['body'],
                'is_important' => $details['is_important'],
                'publishes_at' => $publishesAt,
                'expires_at' => $details['expires_at'],
            ]);
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->recordChanges(AuditAction::AnnouncementUpdated, $locked, $before, $this->snapshot($locked));

            return $locked;
        });
    }

    /** Takes a notice off the portal for good; it stays in the list as withdrawn. */
    public function withdraw(Announcement $announcement, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor): Announcement {
            $locked = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ($locked->withdrawn_at !== null) {
                throw ValidationException::withMessages(['announcement' => 'This notice was already withdrawn.']);
            }

            $locked->withdrawn_at = now();
            $locked->withdrawn_by = $actor->id;
            $locked->save();

            $this->audit->record(AuditAction::AnnouncementWithdrawn, $locked, oldValues: ['title' => $locked->title], actor: $actor);

            return $locked;
        });
    }

    private function ensureEndsLater(CarbonImmutable $publishesAt, ?CarbonImmutable $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt->lte($publishesAt)) {
            throw ValidationException::withMessages(['expires_at' => 'The notice must end after it starts showing.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Announcement $announcement): array
    {
        return [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'audience' => $announcement->audience->value,
            'campus' => $announcement->campus_id === null ? null : Campus::query()->whereKey($announcement->campus_id)->value('name'),
            'class' => $announcement->class_batch_id === null ? null : ClassBatch::query()->whereKey($announcement->class_batch_id)->value('name'),
            'is_important' => $announcement->is_important,
            'publishes_at' => $announcement->publishes_at->utc()->toDateTimeString(),
            'expires_at' => $announcement->expires_at?->utc()->toDateTimeString(),
        ];
    }
}
