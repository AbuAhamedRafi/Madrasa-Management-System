<?php

namespace App\Enums;

enum DocumentVerificationStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

