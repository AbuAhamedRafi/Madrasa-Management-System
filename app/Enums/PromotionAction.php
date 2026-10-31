<?php

namespace App\Enums;

enum PromotionAction: string
{
    case PROMOTE = 'PROMOTE';
    case REPEAT = 'REPEAT';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

