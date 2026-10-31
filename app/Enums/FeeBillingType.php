<?php

namespace App\Enums;

enum FeeBillingType: string
{
    case RECURRING = 'RECURRING';
    case ONE_TIME = 'ONE_TIME';
    case ADMISSION = 'ADMISSION';
    case EXAM = 'EXAM';
    case OTHER = 'OTHER';
}
