<?php

namespace App\Enums;

enum PaymentSlipStatus: string
{
    case UNPAID = 'UNPAID';
    case PARTIALLY_COLLECTED = 'PARTIALLY_COLLECTED';
    case COLLECTED = 'COLLECTED';
    case EXPIRED = 'EXPIRED';
    case VOIDED = 'VOIDED';
    case SUPERSEDED = 'SUPERSEDED';
}

