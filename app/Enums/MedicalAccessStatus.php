<?php

namespace App\Enums;

/**
 * Stored state of an instructor's request to see a full medical record.
 * "Approved" access whose end date has passed is shown as expired
 * (MedicalAccessRequest::displayStatus); no stored state changes when it
 * ends. Values are also enforced by a CHECK constraint.
 */
enum MedicalAccessStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for Approval',
            self::Approved => 'Access Granted',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Revoked => 'Access Withdrawn',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled, self::Revoked => 'neutral',
        };
    }
}
