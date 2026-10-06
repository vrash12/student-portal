<?php

namespace App\Services\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\Permission;
use App\Models\Announcement;
use App\Models\Campus;
use App\Models\User;
use App\Support\ClassScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Where a user may post notices and which notices they see and manage
 * (owner request, 2026-10-06), from permissions and CampusScope, never role
 * names:
 *
 * - every candidate: users who can view all candidates and are not limited
 *   to a campus;
 * - a campus: users who can view all candidates, for the campuses they see;
 * - a class: the classes of the user's ClassScope (every class of their
 *   campus for users who can view all candidates, otherwise the classes
 *   they teach).
 *
 * Users who can view all candidates manage every notice whose audience they
 * may post to; other users manage only their own notices, and only while
 * they still teach the class.
 */
final readonly class AnnouncementScope
{
    private function __construct(
        private User $user,
        private ClassScope $classes,
        private bool $posts,
        private bool $administers,
    ) {}

    public static function for(User $user): self
    {
        return new self(
            $user,
            ClassScope::for($user, Permission::ManageAnnouncements),
            $user->hasPermission(Permission::ManageAnnouncements),
            $user->hasPermission(Permission::ManageAnnouncements) && $user->hasPermission(Permission::ViewAllCandidates),
        );
    }

    /** The scope with the list's campus filter applied (accounts that see every campus only). */
    public function filteredBy(Request $request): self
    {
        return new self($this->user, $this->classes->filteredBy($request), $this->posts, $this->administers);
    }

    public function classScope(): ClassScope
    {
        return $this->classes;
    }

    /** Whether the user manages every notice of their campuses (not only their own). */
    public function administers(): bool
    {
        return $this->administers;
    }

    /**
     * Audiences the user may post to, in display order.
     *
     * @return list<AnnouncementAudience>
     */
    public function audiences(): array
    {
        if (! $this->posts) {
            return [];
        }

        return array_values(array_filter([
            $this->administers && $this->user->campusScope()->isInstitutionWide() ? AnnouncementAudience::Everyone : null,
            $this->administers ? AnnouncementAudience::Campus : null,
            $this->classes->isEmpty() ? null : AnnouncementAudience::ClassBatch,
        ]));
    }

    /**
     * Campuses the user may post to: the campuses they see (every campus,
     * or their own), in the fixed order South, North, East, West.
     *
     * @return list<array{id: int, name: string, code: string, isActive: bool}>
     */
    public function campusOptions(): array
    {
        if (! $this->administers) {
            return [];
        }

        $scope = $this->user->campusScope();

        // A campus that is switched off still has its candidates: notices may reach them.
        return $scope->isInstitutionWide() ? $scope->filterOptions() : self::campusOption($scope->campusId);
    }

    /** Whether the user may post a notice to this audience, campus and class. */
    public function allowsTarget(AnnouncementAudience $audience, ?int $campusId, ?int $classBatchId): bool
    {
        if (! in_array($audience, $this->audiences(), true)) {
            return false;
        }

        return match ($audience) {
            AnnouncementAudience::Everyone => $campusId === null && $classBatchId === null,
            AnnouncementAudience::Campus => $campusId !== null && $classBatchId === null && $this->user->campusScope()->allows($campusId),
            AnnouncementAudience::ClassBatch => $classBatchId !== null && $this->classes->allows($classBatchId),
        };
    }

    /** Whether the user may change or withdraw the notice. */
    public function manages(Announcement $announcement): bool
    {
        if (! $this->allowsTarget($announcement->audience, $announcement->campus_id, $announcement->class_batch_id)) {
            return false;
        }

        return $this->administers || $announcement->created_by === $this->user->id;
    }

    /**
     * Notices the user sees in the staff list: the ones they may manage and,
     * read only, every other notice reaching candidates they work with
     * (notices to every candidate, to their campus, to the classes they teach).
     *
     * @return Builder<Announcement>
     */
    public function visible(): Builder
    {
        $query = Announcement::query();
        if (! $this->posts) {
            return $query->whereRaw('1 = 0');
        }

        $campus = $this->classes->campus;

        return $query->where(function (Builder $query) use ($campus): void {
            // Notices to every candidate reach every campus, so every list shows them.
            $query->where('audience', AnnouncementAudience::Everyone->value);
            if ($this->administers) {
                $query->orWhere(fn (Builder $query) => $campus->constrain($query->where('audience', '!=', AnnouncementAudience::Everyone->value), 'campus_id'));

                return;
            }
            $query->orWhere(fn (Builder $query) => $query->where('audience', AnnouncementAudience::Campus->value)->where('campus_id', $campus->campusId))
                ->orWhere(fn (Builder $query) => $query->where('audience', AnnouncementAudience::ClassBatch->value)->whereIn('class_batch_id', $this->classes->classes()->select('class_batches.id')));
        });
    }

    /**
     * @return list<array{id: int, name: string, code: string, isActive: bool}>
     */
    private static function campusOption(?int $campusId): array
    {
        $campus = $campusId === null ? null : Campus::query()->find($campusId);

        return $campus === null ? [] : [['id' => $campus->id, 'name' => $campus->name, 'code' => $campus->code, 'isActive' => (bool) $campus->is_active]];
    }
}
