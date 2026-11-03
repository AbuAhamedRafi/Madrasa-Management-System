<?php

namespace App\Services\Payments;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Admissions\AdmissionApprovalService;
use App\Services\AuditTrail\AuditLogService;
use Illuminate\Support\Facades\DB;

class AdmissionPaymentClearanceService
{
    public function __construct(
        protected AdmissionApprovalService $admissionApprovalService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Inspect if all admission-required net charges for an enrollment are settled.
     * Returns ['is_settled' => bool, 'required_total' => float, 'required_paid' => float, 'required_outstanding' => float]
     */
    public function getClearanceStatus(int $enrollmentId): array
    {
        // Find all active/issued invoices for this enrollment
        $invoiceIds = Invoice::query()
            ->where('enrollment_id', $enrollmentId)
            ->whereIn('status', ['ISSUED', 'DRAFT'])
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return [
                'is_settled' => false,
                'required_total' => 0.00,
                'required_paid' => 0.00,
                'required_outstanding' => 0.00,
            ];
        }

        // Aggregate mandatory admission items across these invoices
        $stats = InvoiceItem::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('admission_required', true)
            ->selectRaw('COALESCE(SUM(net_amount), 0) as total_required, COALESCE(SUM(paid_amount), 0) as total_paid, COALESCE(SUM(balance_amount), 0) as total_outstanding')
            ->first();

        $totalRequired = (float) ($stats->total_required ?? 0.00);
        $totalPaid = (float) ($stats->total_paid ?? 0.00);
        $totalOutstanding = (float) ($stats->total_outstanding ?? 0.00);

        $isSettled = ($totalRequired > 0.0 && $totalOutstanding <= 0.0001);

        return [
            'is_settled' => $isSettled,
            'required_total' => $totalRequired,
            'required_paid' => $totalPaid,
            'required_outstanding' => max(0.00, $totalOutstanding),
        ];
    }

    /**
     * Check and trigger admission approval if mandatory admission items are settled.
     * Must be called inside the cash collection transaction.
     */
    public function checkAndTriggerApproval(int $enrollmentId, string $paymentReference, int $actorId): bool
    {
        $clearance = $this->getClearanceStatus($enrollmentId);

        if ($clearance['is_settled']) {
            /** @var Enrollment|null $enrollment */
            $enrollment = Enrollment::query()->where('id', $enrollmentId)->lockForUpdate()->first();
            if ($enrollment && $enrollment->status->value === 'PENDING_PAYMENT') {
                $this->admissionApprovalService->approveIfRequiredChargesSettled(
                    enrollmentId: $enrollmentId,
                    paymentReference: $paymentReference,
                    actorId: $actorId
                );

                $this->auditLogService->log(
                    action: 'admission.payment_cleared',
                    actorId: $actorId,
                    targetType: Enrollment::class,
                    targetId: $enrollmentId,
                    payload: [
                        'payment_reference' => $paymentReference,
                        'required_total' => $clearance['required_total'],
                    ]
                );

                return true;
            }
        }

        return false;
    }
}

