<?php

namespace App\Enums;

enum CashierShiftStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case RECONCILED = 'RECONCILED';
}

