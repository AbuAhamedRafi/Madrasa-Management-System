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
     * Computes verified allocations directly from payment_allocations table.
     */
    public function getClearanceStatus(int $enrollmentId): array
    {
        $invoiceIds = Invoice::where('enrollment_id', $enrollmentId)
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

        $requiredItems = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->where('admission_required', true)
            ->get();

        if ($requiredItems->isEmpty()) {
            return [
                'is_settled' => true,
                'required_total' => 0.00,
                'required_paid' => 0.00,
                'required_outstanding' => 0.00,
            ];
        }

        $requiredTotal = 0.00;
        $requiredPaid = 0.00;

        foreach ($requiredItems as $item) {
            $itemNet = (float) $item->net_amount;
            $requiredTotal += $itemNet;

            $paid = (float) DB::table('payment_allocations')
                ->where('invoice_item_id', $item->id)
                ->sum(DB::raw("CASE WHEN direction = 'CREDIT' THEN allocated_amount ELSE -allocated_amount END"));

            $requiredPaid += $paid;
        }

        $requiredOutstanding = max(0.00, round($requiredTotal - $requiredPaid, 2));
        $isSettled = ($requiredTotal > 0.0 && $requiredOutstanding <= 0.0001);

        return [
            'is_settled' => $isSettled,
            'required_total' => round($requiredTotal, 2),
            'required_paid' => round($requiredPaid, 2),
            'required_outstanding' => $requiredOutstanding,
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
