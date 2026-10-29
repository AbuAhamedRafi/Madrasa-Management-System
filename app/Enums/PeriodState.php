<?php

namespace App\Enums;

enum PeriodState: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

