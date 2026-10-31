<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case VOID = 'VOID';
}
