<?php

namespace App\Enums;

enum PromotionItemStatus: string
{
    case PENDING = 'PENDING';
    case ELIGIBLE = 'ELIGIBLE';
    case PROCESSING = 'PROCESSING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';
    case RETRY_QUEUED = 'RETRY_QUEUED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::SKIPPED], true);
    }

    public function canBeRetried(): bool
    {
        return $this === self::FAILED;
    }
}

