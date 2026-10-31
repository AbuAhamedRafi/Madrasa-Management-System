<?php

namespace App\Enums;

enum BillingRunItemStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SUCCEEDED = 'SUCCEEDED';
    case SKIPPED = 'SKIPPED';
    case FAILED = 'FAILED';
}

