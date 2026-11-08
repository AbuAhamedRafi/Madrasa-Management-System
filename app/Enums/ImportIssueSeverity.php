<?php

namespace App\Enums;

enum ImportIssueSeverity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';
    case REVIEW = 'REVIEW';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
