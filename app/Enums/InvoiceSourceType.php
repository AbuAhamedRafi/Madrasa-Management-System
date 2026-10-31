<?php

namespace App\Enums;

enum InvoiceSourceType: string
{
    case ENROLLMENT_ADMISSION = 'ENROLLMENT_ADMISSION';
    case MONTHLY_RUN = 'MONTHLY_RUN';
    case EXAM = 'EXAM';
    case MANUAL = 'MANUAL';
    case OTHER = 'OTHER';
}
