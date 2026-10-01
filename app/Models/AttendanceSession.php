<?php

namespace App\Models;

use App\Policies\AttendanceSessionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training session of a class on a date, with its length in hours and
 * each candidate's attendance. The class is set when the session is created
 * and does not change. Change through AttendanceService.
 */
#[Fillable(['held_on', 'title', 'hours', 'notes'])]
#[UsePolicy(AttendanceSessionPolicy::class)]
class AttendanceSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_batch_id' => 'integer',
            'held_on' => 'date',
            'hours' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
