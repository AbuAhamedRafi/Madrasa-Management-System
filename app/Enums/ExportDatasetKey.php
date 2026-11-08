<?php

namespace App\Enums;

enum ExportDatasetKey: string
{
    case STUDENT_MASTER = 'student_master';
    case ACADEMIC_ENROLLMENTS = 'academic_enrollments';
    case STUDENT_PROFILE_HISTORY = 'student_profile_history';
    case PROMOTION_RESULTS = 'promotion_results';
    case INVOICE_LEDGER = 'invoice_ledger';
    case CASH_COLLECTIONS = 'cash_collections';
    case CASHIER_RECONCILIATION = 'cashier_reconciliation';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
