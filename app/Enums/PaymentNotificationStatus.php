<?php

namespace App\Enums;

enum PaymentNotificationStatus: string
{
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case SKIPPED_NO_CONSENT = 'SKIPPED_NO_CONSENT';
    case SKIPPED_NO_PHONE = 'SKIPPED_NO_PHONE';
}

