<?php

namespace App\Enums;

enum ExportFormat: string
{
    case XLSX = 'XLSX';
    case PDF = 'PDF';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
