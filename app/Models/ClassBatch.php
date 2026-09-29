<?php

namespace App\Models;

use App\Policies\ClassBatchPolicy;
use Database\Factories\ClassBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class / batch of candidates within one academic period. Named ClassBatch
 * because `Class` is reserved in PHP and `Batch` clashes with job batches.
 * The academic period is set when the class is created and does not change.
 */
#[Fillable(['name'])]
#[UsePolicy(ClassBatchPolicy::class)]
class ClassBatch extends Model
{
    /** @use HasFactory<ClassBatchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * @return HasMany<ClassSubject, $this>
     */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }
}
