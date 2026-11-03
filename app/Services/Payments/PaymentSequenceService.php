<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

class PaymentSequenceService
{
    /**
     * Generate an annual collision-safe sequence number.
     * e.g., PS-2026-000001, RC-2026-000001, TXN-2026-000001, CS-2026-000001, REV-2026-000001, RVX-2026-000001
     */
    public function nextNumber(string $prefix, string $table, string $column): string
    {
        $year = (int) date('Y');
        $prefixPattern = sprintf('%s-%d-%%', $prefix, $year);

        // Lock existing records for this prefix/year to find max sequence safely
        $maxRecord = DB::table($table)
            ->where($column, 'LIKE', $prefixPattern)
            ->orderBy($column, 'desc')
            ->lockForUpdate()
            ->value($column);

        $nextSeq = 1;
        if ($maxRecord && preg_match('/' . preg_quote($prefix, '/') . '-\d{4}-(\d{6})/', $maxRecord, $matches)) {
            $nextSeq = (int) $matches[1] + 1;
        }

        return sprintf('%s-%d-%06d', $prefix, $year, $nextSeq);
    }
}

