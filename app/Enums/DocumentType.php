<?php

namespace App\Enums;

enum DocumentType: string
{
    case BIRTH_CERTIFICATE = 'BIRTH_CERTIFICATE';
    case PREVIOUS_TRANSCRIPT = 'PREVIOUS_TRANSCRIPT';
    case NID_GUARDIAN = 'NID_GUARDIAN';
    case MEDICAL = 'MEDICAL';
    case OTHER = 'OTHER';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

