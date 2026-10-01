<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Candidate;
use App\Models\ConductEntry;
use App\Models\User;
use App\Services\Conduct\ConductScope;

/**
 * Merits and demerits: users with conduct.manage act on every candidate when
 * they can view all candidates, otherwise only on candidates whose current
 * class they teach (ConductScope). Knowing a URL never grants access.
 *
 * Usage: `$user->can('manage', [ConductEntry::class, $candidate])` and
 * `$user->can('void', $entry)`.
 */
class ConductPolicy
{
    /** Open the candidate's conduct page and record merits/demerits. */
    public function manage(User $actor, Candidate $candidate): bool
    {
        return $actor->hasPermission(Permission::ManageConduct) && ConductScope::for($actor)->covers($candidate);
    }

    public function void(User $actor, ConductEntry $entry): bool
    {
        $entry->loadMissing('candidate');

        return $this->manage($actor, $entry->candidate);
    }
}
