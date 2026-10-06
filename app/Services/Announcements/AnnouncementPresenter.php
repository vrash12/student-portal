<?php

namespace App\Services\Announcements;

use App\Enums\AnnouncementAudience;
use App\Models\Announcement;
use App\Models\Candidate;
use Carbon\CarbonInterface;

/**
 * Notices as the pages show them: the candidate's portal (only notices
 * showing now and meant for them) and the staff list.
 */
final class AnnouncementPresenter
{
    /** Notices on the portal home page; older ones still show once newer ones end. */
    public const PORTAL_LIMIT = 20;

    /**
     * Notices the candidate sees now: important first, then newest.
     *
     * @return list<array{id: int, title: string, body: string, important: bool, postedAt: string, expiresAt: ?string, audience: string, postedBy: string}>
     */
    public function forCandidate(Candidate $candidate): array
    {
        return Announcement::query()
            ->showing()
            ->forCandidate($candidate)
            ->with(['author:id,name', 'campus:id,name', 'classBatch:id,name'])
            ->orderByDesc('is_important')
            ->orderByDesc('publishes_at')
            ->orderByDesc('id')
            ->limit(self::PORTAL_LIMIT)
            ->get()
            ->map(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'important' => $announcement->is_important,
                'postedAt' => $announcement->publishes_at->toIso8601String(),
                'expiresAt' => $announcement->expires_at?->toIso8601String(),
                'audience' => $this->audienceLabel($announcement),
                'postedBy' => $announcement->author->name,
            ])
            ->values()
            ->all();
    }

    /**
     * One notice in the staff list. Load author, campus and classBatch first.
     *
     * @return array<string, mixed>
     */
    public function staffRow(Announcement $announcement, AnnouncementScope $scope, CarbonInterface $now): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'important' => $announcement->is_important,
            'audience' => ['value' => $announcement->audience->value, 'label' => $this->audienceLabel($announcement)],
            'status' => $announcement->status($now)->toArray(),
            'publishesAt' => $announcement->publishes_at->toIso8601String(),
            'expiresAt' => $announcement->expires_at?->toIso8601String(),
            'withdrawnAt' => $announcement->withdrawn_at?->toIso8601String(),
            'postedBy' => $announcement->author->name,
            'canManage' => $announcement->withdrawn_at === null && $scope->manages($announcement),
        ];
    }

    /** "Every candidate", the campus's name, or the class's name with its campus. */
    public function audienceLabel(Announcement $announcement): string
    {
        return match ($announcement->audience) {
            AnnouncementAudience::Everyone => 'Every candidate',
            AnnouncementAudience::Campus => $announcement->campus->name ?? 'A campus',
            AnnouncementAudience::ClassBatch => trim(($announcement->classBatch->name ?? 'A class').($announcement->campus ? " · {$announcement->campus->name}" : '')),
        };
    }
}
