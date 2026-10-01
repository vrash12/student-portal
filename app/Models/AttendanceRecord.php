<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's attendance status at one session. Rates and hours are
 * calculated by AttendanceLedger, never stored. Changes are recorded in the
 * audit log through AttendanceService.
 */
class AttendanceRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_session_id' => 'integer',
            'candidate_id' => 'integer',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<AttendanceSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
