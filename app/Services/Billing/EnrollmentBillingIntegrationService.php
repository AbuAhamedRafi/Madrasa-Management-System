<?php

namespace App\Services\Billing;

use App\Enums\FeeCadence;
use App\Enums\InvoiceSourceType;
use App\Exceptions\BillingDomainException;
use App\Models\BillingEventInbox;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EnrollmentBillingIntegrationService
{
    public function __construct(
        private readonly FeeStructureService $feeStructureService,
        private readonly InvoiceService $invoiceService
    ) {}

    /**
     * Consume a D04 EnrollmentSubmitted event idempotently.
     *
     * - Deduplicates by event_id AND logical source_key ("ENROLLMENT_ADMISSION:ENR:{id}")
     * - Resolves published FeeStructure
     * - Issues immutable admission invoice
     * - Advances D04 enrollment from PENDING_BILLING -> PENDING_PAYMENT via markPrepared
     */
    public function consumeEnrollmentSubmitted(string $eventId, array $payload, int $actorId = 1): array
    {
        $enrollmentId = (int) ($payload['enrollment_id'] ?? 0);
        if ($enrollmentId <= 0) {
            throw BillingDomainException::validation('INVALID_EVENT_PAYLOAD', 'Missing enrollment_id in EnrollmentSubmitted payload.');
        }

        $sourceKey = "ENROLLMENT_ADMISSION:ENR:{$enrollmentId}";
        $payloadHash = hash('sha256', json_encode($payload));

        // 1. Check if eventId already completed
        $existingInbox = BillingEventInbox::where('event_id', $eventId)->first();
        if ($existingInbox && $existingInbox->status === 'COMPLETED') {
            $existingInvoice = Invoice::where('source_key', $sourceKey)->first();
            if ($existingInvoice) {
                return $this->invoiceService->getInvoiceDetail($existingInvoice->id);
            }
        }

        // 2. Check if logical source_key already has an invoice (C16: different event_id, same logical source_key)
        $existingBySourceKey = Invoice::where('source_key', $sourceKey)->first();
        if ($existingBySourceKey) {
            BillingEventInbox::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'event_type' => 'EnrollmentSubmitted',
                    'aggregate_type' => 'Enrollment',
                    'aggregate_id' => $enrollmentId,
                    'payload_hash' => $payloadHash,
                    'payload_json' => $payload,
                    'status' => 'COMPLETED',
                    'processed_at' => now(),
                ]
            );

            if ($existingBySourceKey->status->value === 'DRAFT') {
                return $this->invoiceService->issueInvoice($existingBySourceKey->id, $actorId);
            }

            return $this->invoiceService->getInvoiceDetail($existingBySourceKey->id);
        }

        $enrollment = Enrollment::with(['student', 'period.program', 'offering.programClass', 'programAdmission'])->find($enrollmentId);
        if (!$enrollment) {
            throw BillingDomainException::notFound('ENROLLMENT_NOT_FOUND', "Enrollment #{$enrollmentId} not found.");
        }

        $this->invoiceService->assertEnrollmentEligibleForBilling($enrollment);

        $programId = (int) ($enrollment->period?->program_id ?? $enrollment->programAdmission?->program_id ?? $payload['program_id'] ?? 0);
        $periodId = (int) $enrollment->academic_period_id;
        $offeringId = $enrollment->academic_offering_id ? (int) $enrollment->academic_offering_id : null;
        $classId = $enrollment->offering?->program_class_id ? (int) $enrollment->offering->program_class_id : null;

        $effectiveDate = $enrollment->period?->start_date?->format('Y-m-d')
            ?? $enrollment->submitted_at?->format('Y-m-d')
            ?? Carbon::now('Asia/Dhaka')->format('Y-m-d');

        try {
            $structure = $this->feeStructureService->resolveEffectiveStructure(
                $programId,
                $periodId,
                $offeringId,
                $classId,
                $effectiveDate
            );
        } catch (BillingDomainException $e) {
            // Record actionable failure in inbox while leaving D04 enrollment in PENDING_BILLING (C17)
            BillingEventInbox::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'event_type' => 'EnrollmentSubmitted',
                    'aggregate_type' => 'Enrollment',
                    'aggregate_id' => $enrollmentId,
                    'payload_hash' => $payloadHash,
                    'payload_json' => $payload,
                    'status' => 'FAILED',
                    'error_message' => "{$e->errorCode}: {$e->getMessage()}",
                ]
            );
            throw $e;
        }

        return DB::transaction(function () use ($eventId, $enrollment, $structure, $sourceKey, $payloadHash, $payload, $actorId) {
            BillingEventInbox::updateOrCreate(
                ['event_id' => $eventId],
                [
                    'event_type' => 'EnrollmentSubmitted',
                    'aggregate_type' => 'Enrollment',
                    'aggregate_id' => $enrollment->id,
                    'payload_hash' => $payloadHash,
                    'payload_json' => $payload,
                    'status' => 'PROCESSING',
                ]
            );

            // Double-check source_key inside transaction
            $existing = Invoice::where('source_key', $sourceKey)->lockForUpdate()->first();
            if ($existing) {
                BillingEventInbox::where('event_id', $eventId)->update([
                    'status' => 'COMPLETED',
                    'processed_at' => now(),
                ]);
                return $this->invoiceService->getInvoiceDetail($existing->id);
            }

            // Select admission/enrollment items from structure
            $applicableItems = $structure->items->filter(function ($item) {
                return $item->admission_required
                    || in_array($item->cadence, [
                        FeeCadence::PER_ENROLLMENT,
                        FeeCadence::ONE_TIME,
                        FeeCadence::ANNUAL,
                    ], true);
            });

            if ($applicableItems->isEmpty()) {
                $applicableItems = $structure->items;
            }

            $lines = [];
            $allZero = true;
            foreach ($applicableItems as $idx => $sItem) {
                if (bccomp((string) $sItem->amount, '0.00', 2) > 0) {
                    $allZero = false;
                }
                $lines[] = [
                    'fee_category_id' => $sItem->fee_category_id,
                    'fee_structure_item_id' => $sItem->id,
                    'source_version' => $structure->version,
                    'description_bn' => $sItem->feeCategory?->name_bn ?? 'ভর্তি ফি',
                    'description_en' => $sItem->feeCategory?->name_en ?? 'Admission Fee',
                    'quantity' => 1,
                    'unit_amount' => number_format((float) $sItem->amount, 2, '.', ''),
                    'admission_required' => (bool) $sItem->admission_required,
                    'sort_order' => $sItem->sort_order ?: ($idx + 1),
                ];
            }

            $invoiceResult = $this->invoiceService->createDraftInvoice([
                'enrollment_id' => $enrollment->id,
                'fee_structure_id' => $structure->id,
                'source_type' => InvoiceSourceType::ENROLLMENT_ADMISSION->value,
                'source_key' => $sourceKey,
                'zero_charge_policy' => $allZero,
                'items' => $lines,
                'auto_issue' => true,
            ], $actorId);

            BillingEventInbox::where('event_id', $eventId)->update([
                'status' => 'COMPLETED',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            return $invoiceResult;
        });
    }

    /**
     * Scan D04 EnrollmentOutbox for any pending EnrollmentSubmitted events and process them.
     */
    public function processOutboxQueue(int $actorId = 1): array
    {
        $events = EnrollmentOutbox::where('event_type', 'EnrollmentSubmitted')
            ->orderBy('created_at')
            ->get();

        $succeeded = 0;
        $failed = 0;
        $errors = [];

        foreach ($events as $outbox) {
            try {
                $this->consumeEnrollmentSubmitted(
                    (string) $outbox->id,
                    (array) $outbox->payload_jsonb,
                    $actorId
                );
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'event_id' => $outbox->id,
                    'enrollment_id' => $outbox->aggregate_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => $succeeded + $failed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
