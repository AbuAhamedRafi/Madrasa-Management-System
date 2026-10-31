<?php

namespace App\Enums;

enum BillingRunType: string
{
    case ENROLLMENT = 'ENROLLMENT';
    case MONTHLY_TUITION = 'MONTHLY_TUITION';
    case EXAM = 'EXAM';
    case OTHER = 'OTHER';
}

