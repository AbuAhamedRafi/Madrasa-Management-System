<?php

namespace App\Enums;

enum AdmissionEntryType: string
{
    case REGULAR = 'REGULAR';
    case TRANSFER_IN = 'TRANSFER_IN';
    case LATERAL_ENTRY = 'LATERAL_ENTRY';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

