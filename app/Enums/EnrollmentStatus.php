<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_BILLING = 'PENDING_BILLING';
    case PENDING_PAYMENT = 'PENDING_PAYMENT';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::CANCELLED], true);
    }

    public function countsTowardsCapacity(): bool
    {
        return in_array($this, [self::PENDING_BILLING, self::PENDING_PAYMENT, self::APPROVED], true);
    }
}
