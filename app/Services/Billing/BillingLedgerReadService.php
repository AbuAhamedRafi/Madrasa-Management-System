<?php

namespace App\Services\Billing;

use App\Enums\AdjustmentState;
use App\Enums\BillingRunStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingDomainException;
use App\Models\BillingEventInbox;
use App\Models\BillingOutbox;
use App\Models\BillingRun;
use App\Models\Enrollment;
use App\Models\FeeAdjustmentRequest;
use App\Models\Invoice;
use App\Models\Student;

class BillingLedgerReadService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {}

    /**
     * Scoped KPIs and operational summary for UI-01 Billing Dashboard.
     */
    public function getBillingSummary(array $filters = []): array
    {
        $issuedQuery = Invoice::where('status', InvoiceStatus::ISSUED->value);
        if (!empty($filters['academic_period_id'])) {
            $issuedQuery->where('academic_period_id', (int) $filters['academic_period_id']);
        }

        $issuedInvoices = $issuedQuery->get();
        $issuedCount = $issuedInvoices->count();

        $totalGross = '0.00';
        $totalAdjustment = '0.00';
        $totalNet = '0.00';
        $totalCollected = '0.00';

        foreach ($issuedInvoices as $inv) {
            $totalGross = bcadd($totalGross, number_format((float) $inv->gross_amount, 2, '.', ''), 2);
            $totalAdjustment = bcadd($totalAdjustment, number_format((float) $inv->adjustment_amount, 2, '.', ''), 2);
            $totalNet = bcadd($totalNet, number_format((float) $inv->net_amount, 2, '.', ''), 2);
            $totalCollected = bcadd($totalCollected, $this->invoiceService->getVerifiedCollectedAmount($inv->id), 2);
        }

        $totalOutstanding = bcsub($totalNet, $totalCollected, 2);
        if (bccomp($totalOutstanding, '0.00', 2) < 0) {
            $totalOutstanding = '0.00';
        }

        $pendingBillingEnrollmentsCount = Enrollment::where('status', EnrollmentStatus::PENDING_BILLING->value)->count();

        $draftRunsCount = BillingRun::whereIn('status', [
            BillingRunStatus::DRAFT->value,
            BillingRunStatus::PREVIEWED->value,
        ])->count();

        $pendingAdjustmentsCount = FeeAdjustmentRequest::where('state', AdjustmentState::PENDING->value)->count();

        $recentRuns = BillingRun::with(['period.program', 'offering.programClass'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (BillingRun $r) => [
                'id' => $r->id,
                'run_number' => $r->run_number,
                'type' => $r->type->value ?? $r->type,
                'academic_period_label' => $r->period?->label,
                'program_name_bn' => $r->period?->program?->name_bn,
                'program_name_en' => $r->period?->program?->name_en,
                'billing_month' => $r->billing_month?->format('Y-m'),
                'status' => $r->status->value ?? $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all();

        $unbilledEnrollments = Enrollment::with(['student', 'period.program', 'offering.programClass'])
            ->where('status', EnrollmentStatus::PENDING_BILLING->value)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'student_record_id' => $e->student_id,
                'student_public_id' => $e->student?->student_id,
                'student_name_bn' => $e->student?->name_bn,
                'student_name_en' => $e->student?->name_en,
                'program_name_bn' => $e->period?->program?->name_bn,
                'program_name_en' => $e->period?->program?->name_en,
                'academic_period_label' => $e->period?->label,
                'class_name_bn' => $e->offering?->programClass?->name_bn,
                'class_name_en' => $e->offering?->programClass?->name_en,
                'status' => $e->status->value ?? $e->status,
                'submitted_at' => $e->submitted_at?->toIso8601String(),
            ])->values()->all();

        return [
            'kpis' => [
                'issued_invoices_count' => $issuedCount,
                'issued_invoices_gross' => $totalGross,
                'issued_invoices_adjustment' => $totalAdjustment,
                'issued_invoices_net' => $totalNet,
                'verified_collected_amount' => $totalCollected,
                'calculated_outstanding_amount' => $totalOutstanding,
                'pending_billing_enrollments_count' => $pendingBillingEnrollmentsCount,
                'draft_billing_runs_count' => $draftRunsCount,
                'pending_adjustment_requests_count' => $pendingAdjustmentsCount,
                'settlement_source' => 'D07_NOT_CONNECTED',
            ],
            'recent_runs' => $recentRuns,
            'unbilled_enrollments' => $unbilledEnrollments,
        ];
    }

    /**
     * Student billing ledger by D03 internal primary key (UI-08, F10, C52, C53, C60).
     */
    public function getStudentLedger(int $studentRecordId, array $filters = []): array
    {
        $student = Student::find($studentRecordId);
        if (!$student) {
            throw BillingDomainException::notFound('STUDENT_NOT_FOUND', "Student record #{$studentRecordId} not found.");
        }

        $invoicesQuery = Invoice::with([
            'student',
            'enrollment.offering.programClass',
            'enrollment.section',
            'period.program',
            'items.feeCategory',
            'activeInstallmentPlan.items',
        ])->where('student_id', $student->id);

        if (!empty($filters['academic_period_id'])) {
            $invoicesQuery->where('academic_period_id', (int) $filters['academic_period_id']);
        }
        if (!empty($filters['status'])) {
            $invoicesQuery->where('status', $filters['status']);
        }
        if (!empty($filters['enrollment_id'])) {
            $invoicesQuery->where('enrollment_id', (int) $filters['enrollment_id']);
        }

        $invoices = $invoicesQuery->orderByDesc('id')->get();

        $totalGross = '0.00';
        $totalAdjustment = '0.00';
        $totalNet = '0.00';
        $totalRequiredAdmissionNet = '0.00';
        $totalCollected = '0.00';

        $formattedInvoices = [];
        foreach ($invoices as $inv) {
            $formatted = $this->invoiceService->formatInvoicePayload($inv);
            $formattedInvoices[] = $formatted;

            if ($inv->status === InvoiceStatus::ISSUED) {
                $totalGross = bcadd($totalGross, $formatted['gross_amount'], 2);
                $totalAdjustment = bcadd($totalAdjustment, $formatted['adjustment_amount'], 2);
                $totalNet = bcadd($totalNet, $formatted['net_amount'], 2);
                $totalRequiredAdmissionNet = bcadd($totalRequiredAdmissionNet, $formatted['required_admission_net'], 2);
                $totalCollected = bcadd($totalCollected, $formatted['verified_collected_amount'], 2);
            }
        }

        $totalOutstanding = bcsub($totalNet, $totalCollected, 2);
        if (bccomp($totalOutstanding, '0.00', 2) < 0) {
            $totalOutstanding = '0.00';
        }

        $enrollments = Enrollment::with(['period.program', 'offering.programClass', 'section'])
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'academic_period_id' => $e->academic_period_id,
                'academic_period_label' => $e->period?->label,
                'program_code' => $e->period?->program?->code,
                'program_name_bn' => $e->period?->program?->name_bn,
                'program_name_en' => $e->period?->program?->name_en,
                'class_name_bn' => $e->offering?->programClass?->name_bn,
                'class_name_en' => $e->offering?->programClass?->name_en,
                'section_name_bn' => $e->section?->name_bn,
                'section_name_en' => $e->section?->name_en,
                'enrollment_type' => $e->enrollment_type->value ?? $e->enrollment_type,
                'status' => $e->status->value ?? $e->status,
                'billing_cleared' => (bool) $e->billing_cleared,
                'payment_cleared' => (bool) $e->payment_cleared,
            ])->values()->all();

        return [
            'student' => [
                'record_id' => $student->id,
                'public_student_id' => $student->student_id,
                'name_bn' => $student->name_bn,
                'name_en' => $student->name_en,
                'status' => $student->status->value ?? $student->status,
            ],
            'reconciliation_summary' => [
                'currency' => 'BDT',
                'total_gross_amount' => $totalGross,
                'total_adjustment_amount' => $totalAdjustment,
                'total_net_amount' => $totalNet,
                'total_required_admission_net' => $totalRequiredAdmissionNet,
                'total_verified_collected_amount' => $totalCollected,
                'total_outstanding_amount' => $totalOutstanding,
                'settlement_source' => bccomp($totalCollected, '0.00', 2) > 0 ? 'D07_VERIFIED' : 'NOT_CONNECTED',
            ],
            'enrollments' => $enrollments,
            'invoices' => $formattedInvoices,
        ];
    }

    /**
     * Typed internal read contract for D07 & D04 to inspect required-admission liabilities
     * vs non-required charges (F11, VAL-12, VAL-13, C48, C49, C61).
     */
    public function getEnrollmentBillingLiability(int $enrollmentId): array
    {
        $enrollment = Enrollment::find($enrollmentId);
        if (!$enrollment) {
            throw BillingDomainException::notFound('ENROLLMENT_NOT_FOUND', "Enrollment #{$enrollmentId} not found.");
        }

        $invoices = Invoice::with('items')
            ->where('enrollment_id', $enrollment->id)
            ->where('status', InvoiceStatus::ISSUED->value)
            ->get();

        $requiredNet = '0.00';
        $nonRequiredNet = '0.00';
        $requiredCollected = '0.00';
        $nonRequiredCollected = '0.00';
        $requiredItems = [];

        foreach ($invoices as $inv) {
            $itemAllocations = $this->getVerifiedItemAllocations($inv->id);

            foreach ($inv->items as $item) {
                $lineNet = number_format((float) $item->net_amount, 2, '.', '');
                $lineCollected = $itemAllocations[$item->id] ?? '0.00';

                if ($item->admission_required) {
                    $requiredNet = bcadd($requiredNet, $lineNet, 2);
                    $requiredCollected = bcadd($requiredCollected, $lineCollected, 2);
                    $requiredItems[] = [
                        'invoice_id' => $inv->id,
                        'invoice_number' => $inv->invoice_number,
                        'invoice_item_id' => $item->id,
                        'description_en' => $item->description_en,
                        'net_amount' => $lineNet,
                        'verified_collected_amount' => $lineCollected,
                        'outstanding_amount' => max(0, (float) bcsub($lineNet, $lineCollected, 2)) > 0
                            ? bcsub($lineNet, $lineCollected, 2)
                            : '0.00',
                    ];
                } else {
                    $nonRequiredNet = bcadd($nonRequiredNet, $lineNet, 2);
                    $nonRequiredCollected = bcadd($nonRequiredCollected, $lineCollected, 2);
                }
            }
        }

        $requiredOutstanding = bcsub($requiredNet, $requiredCollected, 2);
        if (bccomp($requiredOutstanding, '0.00', 2) < 0) {
            $requiredOutstanding = '0.00';
        }

        $hasZeroClearanceEvidence = BillingOutbox::where('event_type', 'ZeroChargeClearanceEvidenceIssued')
            ->whereIn('aggregate_id', $invoices->pluck('id')->all())
            ->exists();

        $isRequiredSettled = $invoices->isNotEmpty() && bccomp($requiredOutstanding, '0.00', 2) === 0
            && (bccomp($requiredNet, '0.00', 2) > 0 ? bccomp($requiredCollected, $requiredNet, 2) >= 0 : $hasZeroClearanceEvidence);

        return [
            'enrollment_id' => $enrollment->id,
            'enrollment_status' => $enrollment->status->value ?? $enrollment->status,
            'has_issued_invoice' => $invoices->isNotEmpty(),
            'required_admission_net' => $requiredNet,
            'required_admission_collected' => $requiredCollected,
            'required_admission_outstanding' => $requiredOutstanding,
            'non_required_net' => $nonRequiredNet,
            'non_required_collected' => $nonRequiredCollected,
            'is_required_admission_settled' => $isRequiredSettled,
            'has_verifiable_zero_clearance_evidence' => $hasZeroClearanceEvidence,
            'required_items' => $requiredItems,
        ];
    }

    /**
     * Consume authoritative PaymentAllocated projection from future D07 idempotently.
     */
    public function consumePaymentAllocated(string $eventId, int $invoiceId, string $allocatedAmount, array $itemAllocations = []): void
    {
        $formattedTotal = number_format((float) $allocatedAmount, 2, '.', '');
        $payload = [
            'invoice_id' => $invoiceId,
            'allocated_amount' => $formattedTotal,
            'item_allocations' => $itemAllocations,
        ];

        BillingEventInbox::updateOrCreate(
            ['event_id' => $eventId],
            [
                'event_type' => 'PaymentAllocated',
                'aggregate_type' => 'Invoice',
                'aggregate_id' => $invoiceId,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'payload_json' => $payload,
                'status' => 'COMPLETED',
                'processed_at' => now(),
            ]
        );
    }

    private function getVerifiedItemAllocations(int $invoiceId): array
    {
        $events = BillingEventInbox::where('event_type', 'PaymentAllocated')
            ->where('aggregate_id', $invoiceId)
            ->where('status', 'COMPLETED')
            ->get();

        $map = [];
        foreach ($events as $ev) {
            $allocs = $ev->payload_json['item_allocations'] ?? [];
            foreach ($allocs as $itemId => $amt) {
                $prev = $map[$itemId] ?? '0.00';
                $map[$itemId] = bcadd($prev, number_format((float) $amt, 2, '.', ''), 2);
            }
        }

        return $map;
    }
}

