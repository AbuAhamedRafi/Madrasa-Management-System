<?php

namespace App\Enums;

enum PromotionBatchStatus: string
{
    case DRAFT = 'DRAFT';
    case PREVIEWED = 'PREVIEWED';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case PAUSING = 'PAUSING';
    case PAUSED = 'PAUSED';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';
    case CANCELLED = 'CANCELLED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::CANCELLED], true);
    }

    public function canBeExecuted(): bool
    {
        return in_array($this, [self::DRAFT, self::PREVIEWED], true);
    }

    public function canBePaused(): bool
    {
        return in_array($this, [self::QUEUED, self::RUNNING], true);
    }

    public function canBeResumed(): bool
    {
        return $this === self::PAUSED;
    }

    public function canBeRetried(): bool
    {
        return $this === self::COMPLETED_WITH_ERRORS;
    }
}

