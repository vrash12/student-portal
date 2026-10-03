<?php

namespace App\Models;

use App\Enums\CampusCode;
use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A campus of the institution (owner request, 2026-10-03): one organization
 * with several campuses, not separate tenants. Classes, candidates and
 * instructors belong to a campus; staff accounts without a campus see every
 * campus (App\Support\CampusScope). Academic years, training phases and the
 * other settings are shared by all campuses.
 *
 * There are exactly four campuses, South, North, East and West (owner
 * decision 2026-10-04, App\Enums\CampusCode): none is added or removed;
 * an administrator may change the address and switch one off. Change them
 * through App\Services\CampusService.
 */
#[Fillable(['name', 'code', 'address'])]
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ClassBatch, $this>
     */
    public function classBatches(): HasMany
    {
        return $this->hasMany(ClassBatch::class);
    }

    /**
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * Staff accounts limited to this campus (instructors and campus administrators).
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The fixed campus this is (null only for a stray campus left by an old database). */
    public function fixedCode(): ?CampusCode
    {
        return CampusCode::tryFrom($this->code);
    }

    /** Position in lists: South, North, East, West, then anything else. */
    public function position(): int
    {
        return $this->fixedCode()?->position() ?? count(CampusCode::cases());
    }

    /**
     * @return array{id: int, name: string, code: string}
     */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code];
    }

    /**
     * @param  Builder<Campus>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
