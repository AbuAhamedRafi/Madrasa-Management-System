<?php

namespace App\Enums;

enum ProgramState: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

