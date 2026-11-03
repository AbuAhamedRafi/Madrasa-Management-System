<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
}

