<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case PENDING = 'PENDING';
    case READY = 'READY';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case INVALID = 'INVALID';
    case COMMITTED = 'COMMITTED';
    case SKIPPED = 'SKIPPED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
