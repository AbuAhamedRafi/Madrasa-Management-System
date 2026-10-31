<?php

namespace App\Enums;

enum PromotionBatchMode: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case BULK = 'BULK';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

