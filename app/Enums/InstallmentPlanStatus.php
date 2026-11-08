<?php

namespace App\Enums;

enum InstallmentPlanStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REPLACED = 'REPLACED';
    case CANCELLED = 'CANCELLED';
}

