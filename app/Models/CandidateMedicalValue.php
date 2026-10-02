<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's answer to one medical record field (at most one per field).
 * An empty answer is no row. Change through MedicalRecordService, which keeps
 * every change as a CandidateMedicalRevision.
 */
class CandidateMedicalValue extends Model
{
    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<MedicalField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(MedicalField::class, 'medical_field_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
