<?php

namespace App\Enums;

enum AcademicScheme: string
{
    case CALENDAR_YEAR = 'CALENDAR_YEAR';
    case SESSION_BASED = 'SESSION_BASED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

