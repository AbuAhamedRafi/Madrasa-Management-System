<?php

namespace App\Enums;

enum BillingRunStatus: string
{
    case DRAFT = 'DRAFT';
    case PREVIEWED = 'PREVIEWED';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';
    case FAILED = 'FAILED';
}

