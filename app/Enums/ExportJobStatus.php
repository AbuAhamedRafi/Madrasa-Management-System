<?php

namespace App\Enums;

enum ExportJobStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case READY = 'READY';
    case FAILED = 'FAILED';
    case CANCELLING = 'CANCELLING';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::READY,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
        ], true);
    }
}
