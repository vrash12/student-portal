<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Database row mirroring a case of App\Enums\Permission.
 *
 * Rows are created and removed by AccessControlSeeder; application code
 * checks permissions through the enum and Laravel's Gate.
 */
#[Fillable(['code', 'name', 'description', 'group'])]
class Permission extends Model
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
