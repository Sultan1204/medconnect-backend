<?php

namespace App\Modules\Prescriptions\Enums;

enum PrescriptionStatus: string
{
    case Draft = 'draft';
    case PendingValidation = 'pending_validation';
    case PendingExternalApproval = 'pending_external_approval';
    case Issued = 'issued';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Dispensed = 'dispensed';

    /** Statuses in which the prescriber may still edit the content. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingValidation], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::PendingValidation, self::PendingExternalApproval, self::Issued], true);
    }
}
