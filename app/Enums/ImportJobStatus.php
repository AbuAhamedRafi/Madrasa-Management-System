<?php

namespace App\Enums;

enum ImportJobStatus: string
{
    case UPLOADED = 'UPLOADED';
    case MAPPED = 'MAPPED';
    case VALIDATING = 'VALIDATING';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case READY = 'READY';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';
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
            self::COMPLETED,
            self::COMPLETED_WITH_ERRORS,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
        ], true);
    }
}
