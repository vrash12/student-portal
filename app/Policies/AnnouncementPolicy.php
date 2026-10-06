<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcements\AnnouncementScope;

/**
 * Notices to candidates (AnnouncementScope decides where a user may post
 * and which notices they manage). A withdrawn notice is history and never
 * changes again. Knowing a notice's URL never grants access to it.
 */
class AnnouncementPolicy
{
    /** The Announcements page: anyone who may post a notice somewhere. */
    public function viewAny(User $actor): bool
    {
        return AnnouncementScope::for($actor)->audiences() !== [];
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    /** Change or withdraw the notice. */
    public function manage(User $actor, Announcement $announcement): bool
    {
        return $announcement->withdrawn_at === null && AnnouncementScope::for($actor)->manages($announcement);
    }
}
