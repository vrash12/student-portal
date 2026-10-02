<?php

namespace App\Enums;

/**
 * Stored state of an instructor's request to download a medical document.
 * An approval whose end has passed is shown as ended
 * (MedicalDownloadRequest::displayStatus); no stored state changes when it
 * ends. Values are also enforced by a CHECK constraint.
 */
enum MedicalDownloadStatus: string
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
            self::Approved => 'Download Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Revoked => 'Download Withdrawn',
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
