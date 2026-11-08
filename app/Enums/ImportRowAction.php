<?php

namespace App\Enums;

enum ImportRowAction: string
{
    case CREATE = 'CREATE';
    case MATCH_UPDATE = 'MATCH_UPDATE';
    case ENROLL = 'ENROLL';
    case SKIP = 'SKIP';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
