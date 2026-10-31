<?php

namespace App\Enums;

enum DuplicateReviewStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

