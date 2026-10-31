<?php

namespace App\Services\Promotions;

use App\Models\PromotionBatch;
use App\Models\PromotionItem;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromotionExportService
{
    /**
     * Export batch promotion items to CSV with BOM and formula sanitization.
     */
    public function exportBatchItemsCsv(int $batchId): StreamedResponse
    {
        $batch = PromotionBatch::with([
            'sourceAcademicPeriod',
            'targetAcademicPeriod',
            'targetAcademicOffering.programClass',
        ])->findOrFail($batchId);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"promotion_batch_{$batch->batch_number}.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($batch) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel Bangla rendering
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Write Header
            fputcsv($handle, [
                'Item ID',
                'Batch Number',
                'Student Permanent ID',
                'Student Name (EN)',
                'Student Name (BN)',
                'Source Period',
                'Source Class',
                'Source Section',
                'Target Period',
                'Target Class',
                'Target Section',
                'Action',
                'Eligibility Confirmed',
                'Status',
                'Target Enrollment ID',
                'Error Code',
                'Error Message',
                'Attempts',
            ]);

            PromotionItem::with([
                'student',
                'sourceEnrollment.period',
                'sourceEnrollment.offering.programClass',
                'sourceEnrollment.section',
                'targetOfferingSection.offering.programClass',
            ])
            ->where('batch_id', $batch->id)
            ->orderBy('id', 'asc')
            ->chunk(100, function ($items) use ($handle, $batch) {
                foreach ($items as $item) {
                    $row = [
                        $item->id,
                        $batch->batch_number,
                        $this->sanitize($item->student->student_id ?? ''),
                        $this->sanitize($item->student->name_en ?? ''),
                        $this->sanitize($item->student->name_bn ?? ''),
                        $this->sanitize($item->sourceEnrollment?->period?->label ?? ''),
                        $this->sanitize($item->sourceEnrollment?->offering?->programClass?->name_en ?? ''),
                        $this->sanitize($item->sourceEnrollment?->section?->code ?? ''),
                        $this->sanitize($batch->targetAcademicPeriod?->label ?? ''),
                        $this->sanitize($item->targetOfferingSection?->offering?->programClass?->name_en ?? ''),
                        $this->sanitize($item->targetOfferingSection?->code ?? ''),
                        $item->action->value,
                        $item->academic_eligibility_confirmed ? 'YES' : 'NO',
                        $item->status->value,
                        $item->target_enrollment_id ?? '',
                        $this->sanitize($item->error_code ?? ''),
                        $this->sanitize($item->safe_error_message ?? ''),
                        $item->attempt_count,
                    ];

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Sanitize string against formula injection in Excel/Sheets.
     */
    protected function sanitize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $firstChar = substr($value, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}

