<?php

namespace App\Enums;

enum CohortStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

