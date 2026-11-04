<?php

namespace App\Services\Payments;

use App\Enums\PaymentSlipStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Enums\ReversalRequestStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalRequest;
use App\Models\PaymentSlip;
use App\Models\User;
use App\Services\AuditTrail\AuditLogService;
use App\Services\Billing\BillingLedgerReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentReversalService
{
    public function __construct(
        protected PaymentSequenceService $sequenceService,
        protected BillingLedgerReadService $billingLedgerReadService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Submit a reasoned reversal request.
     */
    public function requestReversal(Payment $payment, array $data, User $actor): PaymentReversalRequest
    {
        return DB::transaction(function () use ($payment, $data, $actor) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::POSTED) {
                throw ValidationException::withMessages([
                    'payment_id' => ['PAYMENT_NOT_POSTED: Only posted payments can be requested for reversal.'],
                ]);
            }

            // Check if there is already a pending reversal request
            $pending = PaymentReversalRequest::query()
                ->where('payment_id', $lockedPayment->id)
                ->where('status', ReversalRequestStatus::PENDING->value)
                ->first();

            if ($pending) {
                throw ValidationException::withMessages([
                    'payment_id' => ['REVERSAL_ALREADY_REQUESTED: A pending reversal request already exists for this payment.'],
                ]);
            }

            $reqNumber = $this->sequenceService->nextNumber('REV', 'payment_reversal_requests', 'request_number');

            $reversalRequest = PaymentReversalRequest::create([
                'request_number' => $reqNumber,
                'payment_id' => $lockedPayment->id,
                'invoice_id' => $lockedPayment->invoice_id,
                'student_id' => $lockedPayment->student_id,
                'status' => ReversalRequestStatus::PENDING,
                'reason_category' => $data['reason_category'] ?? 'DATA_ENTRY_ERROR',
                'reason_details' => $data['reason_details'],
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'payment_reversal.requested',
                actorId: $actor->id,
                targetType: PaymentReversalRequest::class,
                targetId: $reversalRequest->id,
                payload: [
                    'request_number' => $reqNumber,
                    'payment_id' => $lockedPayment->id,
                    'reason_details' => $data['reason_details'],
                ]
            );

            return $reversalRequest;
        });
    }

    /**
     * Approve a payment reversal request with compensating entries.
     * Enforces dual control: requester cannot approve.
     */
    public function approveReversal(PaymentReversalRequest $reversalRequest, array $data, User $approver): PaymentReversal
    {
        return DB::transaction(function () use ($reversalRequest, $data, $approver) {
            /** @var PaymentReversalRequest $lockedReq */
            $lockedReq = PaymentReversalRequest::query()->where('id', $reversalRequest->id)->lockForUpdate()->firstOrFail();

            if ($lockedReq->status !== ReversalRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['REVERSAL_REQUEST_NOT_PENDING: Request is not in PENDING status.'],
                ]);
            }

            // Dual control check: requester != approver
            if ($lockedReq->requested_by === $approver->id) {
                throw ValidationException::withMessages([
                    'approver_id' => ['SELF_APPROVAL_FORBIDDEN: You cannot approve your own payment reversal request.'],
                ]);
            }

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->where('id', $lockedReq->payment_id)->lockForUpdate()->firstOrFail();
            if ($lockedPayment->status !== PaymentStatus::POSTED) {
                throw ValidationException::withMessages([
                    'status' => ['PAYMENT_ALREADY_REVERSED: Payment has already been reversed.'],
                ]);
            }

            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::query()->where('id', $lockedPayment->invoice_id)->lockForUpdate()->firstOrFail();

            /** @var PaymentSlip $lockedSlip */
            $lockedSlip = PaymentSlip::query()->where('id', $lockedPayment->payment_slip_id)->lockForUpdate()->firstOrFail();

            // Create PaymentReversal
            $reversalRef = $this->sequenceService->nextNumber('RVX', 'payment_reversals', 'reversal_reference');
            $reversedAmount = (float) $lockedPayment->amount_received;

            // Check if reversal affects an approved enrollment's mandatory admission items
            $admissionReviewFlag = 'NONE';
            if ($lockedPayment->admission_approved_triggered || ($lockedInvoice->enrollment && $lockedInvoice->enrollment->status->value === 'APPROVED')) {
                $hasAdmissionAlloc = PaymentAllocation::where('payment_id', $lockedPayment->id)
                    ->where('admission_required', true)
                    ->exists();

                if ($hasAdmissionAlloc) {
                    $admissionReviewFlag = 'FLAGGED_FOR_REVIEW';
                }
            }

            $reversal = PaymentReversal::create([
                'reversal_reference' => $reversalRef,
                'reversal_request_id' => $lockedReq->id,
                'payment_id' => $lockedPayment->id,
                'invoice_id' => $lockedInvoice->id,
                'student_id' => $lockedPayment->student_id,
                'enrollment_id' => $lockedPayment->enrollment_id,
                'cashier_shift_id' => $lockedPayment->cashier_shift_id,
                'reversed_amount' => $reversedAmount,
                'admission_review_flag' => $admissionReviewFlag,
                'approved_by' => $approver->id,
                'reversed_at' => now(),
                'notes' => $data['notes'] ?? $lockedReq->reason_details,
            ]);

            // Create compensating DEBIT_REVERSAL allocations
            $originalAllocations = PaymentAllocation::where('payment_id', $lockedPayment->id)->get();
            $compensatingItemAllocations = [];

            foreach ($originalAllocations as $origAlloc) {
                $allocAmt = (float) $origAlloc->allocated_amount;

                PaymentAllocation::create([
                    'payment_id' => $lockedPayment->id,
                    'payment_reversal_id' => $reversal->id,
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_item_id' => $origAlloc->invoice_item_id,
                    'direction' => 'DEBIT_REVERSAL',
                    'allocated_amount' => -$allocAmt,
                    'admission_required' => $origAlloc->admission_required,
                    'item_balance_before' => $origAlloc->item_balance_after,
                    'item_balance_after' => $origAlloc->item_balance_before,
                    'created_by' => $approver->id,
                    'created_at' => now(),
                ]);

                $compensatingItemAllocations[$origAlloc->invoice_item_id] = (string) number_format(-$allocAmt, 2, '.', '');
            }

            // Feed compensating negative event into D06 BillingLedgerReadService
            $eventId = Str::uuid()->toString();
            $this->billingLedgerReadService->consumePaymentAllocated(
                eventId: $eventId,
                invoiceId: $lockedInvoice->id,
                allocatedAmount: (string) number_format(-$reversedAmount, 2, '.', ''),
                itemAllocations: $compensatingItemAllocations
            );

            // Restore PaymentSlip balances and status
            $newSlipCollected = max(0.00, round((float) $lockedSlip->collected_amount - $reversedAmount, 2));
            $newSlipRemaining = min((float) $lockedSlip->slip_amount, round((float) $lockedSlip->slip_amount - $newSlipCollected, 2));
            $newSlipStatus = ($newSlipCollected <= 0.0001) ? PaymentSlipStatus::UNPAID : PaymentSlipStatus::PARTIALLY_COLLECTED;

            $lockedSlip->update([
                'collected_amount' => $newSlipCollected,
                'remaining_amount' => $newSlipRemaining,
                'status' => $newSlipStatus,
            ]);

            // Mark Payment and Receipt REVERSED
            $lockedPayment->update(['status' => PaymentStatus::REVERSED]);
            PaymentReceipt::where('payment_id', $lockedPayment->id)->update([
                'status' => ReceiptStatus::REVERSED,
                'reversed_at' => now(),
            ]);

            // Update Request
            $lockedReq->update([
                'status' => ReversalRequestStatus::APPROVED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'] ?? 'Reversal approved by supervisor.',
            ]);

            PaymentOutbox::create([
                'event_id' => Str::uuid()->toString(),
                'event_type' => 'PaymentReversed',
                'aggregate_type' => 'Payment',
                'aggregate_id' => $lockedPayment->id,
                'payload' => [
                    'payment_id' => $lockedPayment->id,
                    'reversal_id' => $reversal->id,
                    'reversal_reference' => $reversalRef,
                    'reversed_amount' => $reversedAmount,
                    'admission_review_flag' => $admissionReviewFlag,
                ],
                'status' => 'PENDING',
                'available_at' => now(),
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'payment_reversal.approved',
                actorId: $approver->id,
                targetType: PaymentReversal::class,
                targetId: $reversal->id,
                payload: [
                    'reversal_reference' => $reversalRef,
                    'reversed_amount' => $reversedAmount,
                    'admission_review_flag' => $admissionReviewFlag,
                ]
            );

            return $reversal;
        });
    }

    /**
     * Reject a payment reversal request.
     */
    public function rejectReversal(PaymentReversalRequest $reversalRequest, array $data, User $approver): PaymentReversalRequest
    {
        return DB::transaction(function () use ($reversalRequest, $data, $approver) {
            /** @var PaymentReversalRequest $lockedReq */
            $lockedReq = PaymentReversalRequest::query()->where('id', $reversalRequest->id)->lockForUpdate()->firstOrFail();

            if ($lockedReq->status !== ReversalRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['REVERSAL_REQUEST_NOT_PENDING: Request is not in PENDING status.'],
                ]);
            }

            if ($lockedReq->requested_by === $approver->id) {
                throw ValidationException::withMessages([
                    'approver_id' => ['SELF_APPROVAL_FORBIDDEN: You cannot reject/review your own payment reversal request.'],
                ]);
            }

            $lockedReq->update([
                'status' => ReversalRequestStatus::REJECTED,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'] ?? 'Reversal rejected by supervisor.',
            ]);

            $this->auditLogService->log(
                action: 'payment_reversal.rejected',
                actorId: $approver->id,
                targetType: PaymentReversalRequest::class,
                targetId: $lockedReq->id,
                payload: ['request_number' => $lockedReq->request_number]
            );

            return $lockedReq;
        });
    }
}
