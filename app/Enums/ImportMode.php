<?php

namespace App\Enums;

enum ImportMode: string
{
    case CREATE_ONLY = 'CREATE_ONLY';
    case MATCH_UPDATE = 'MATCH_UPDATE';
    case ENROLL_ONLY = 'ENROLL_ONLY';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
