<?php

namespace App\Services\Payments;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Admissions\AdmissionApprovalService;
use App\Services\AuditTrail\AuditLogService;
use App\Services\Billing\BillingLedgerReadService;
use Illuminate\Support\Facades\DB;

class AdmissionPaymentClearanceService
{
    public function __construct(
        protected AdmissionApprovalService $admissionApprovalService,
        protected BillingLedgerReadService $billingLedgerReadService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Inspect if all admission-required net charges for an enrollment are settled.
     * Uses BillingLedgerReadService to read verified ledger allocations.
     */
    public function getClearanceStatus(int $enrollmentId): array
    {
        $liability = $this->billingLedgerReadService->getEnrollmentBillingLiability($enrollmentId);

        return [
            'is_settled' => (bool) ($liability['is_required_admission_settled'] ?? false),
            'required_total' => (float) ($liability['required_admission_net'] ?? 0.00),
            'required_paid' => (float) ($liability['required_admission_collected'] ?? 0.00),
            'required_outstanding' => (float) ($liability['required_admission_outstanding'] ?? 0.00),
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
