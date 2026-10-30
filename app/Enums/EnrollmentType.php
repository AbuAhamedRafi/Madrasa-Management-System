<?php

namespace App\Enums;

enum EnrollmentType: string
{
    case NEW = 'NEW';
    case CONTINUING = 'CONTINUING';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
