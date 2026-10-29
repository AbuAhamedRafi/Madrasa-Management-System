<?php

namespace App\Enums;

enum GuardianRelation: string
{
    case FATHER = 'FATHER';
    case MOTHER = 'MOTHER';
    case LEGAL_GUARDIAN = 'LEGAL_GUARDIAN';
    case OTHER = 'OTHER';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

