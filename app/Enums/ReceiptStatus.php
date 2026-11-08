<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case VALID = 'VALID';
    case REVERSED = 'REVERSED';
}

