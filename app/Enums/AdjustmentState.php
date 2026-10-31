<?php

namespace App\Enums;

enum AdjustmentState: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case APPLIED = 'APPLIED';
}
