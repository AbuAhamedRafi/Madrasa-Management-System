<?php

namespace App\Enums;

enum SupportedLocale: string
{
    case BN_BD = 'bn-BD';
    case EN = 'en';

    public static function default(): self
    {
        return self::BN_BD;
    }
}

