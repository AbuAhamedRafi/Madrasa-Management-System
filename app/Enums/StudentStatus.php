<?php

namespace App\Enums;

enum StudentStatus: string
{
    case REGISTERED = 'REGISTERED';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case WITHDRAWN = 'WITHDRAWN';
    case TRANSFERRED = 'TRANSFERRED';
    case GRADUATED = 'GRADUATED';
    case ARCHIVED = 'ARCHIVED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

