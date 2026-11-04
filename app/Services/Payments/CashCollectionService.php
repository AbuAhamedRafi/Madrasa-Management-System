<?php

namespace App\Services\Payments;

use App\Enums\PaymentSlipStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Models\CashierShift;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentIdempotency;
use App\Models\PaymentOutbox;
use App\Models\PaymentReceipt;
use App\Models\PaymentSlip;
use App\Models\User;
use App\Services\AuditTrail\AuditLogService;
use App\Services\Billing\BillingLedgerReadService;
use App\Services\Billing\InvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashCollectionService
{
    public function __construct(
        protected PaymentSequenceService $sequenceService,
        protected SlipVerificationTokenService $tokenService,
        protected CashierShiftService $shiftService,
        protected AdmissionPaymentClearanceService $admissionClearanceService,
        protected GuardianPaymentNotificationService $notificationService,
        protected BillingLedgerReadService $billingLedgerReadService,
        protected InvoiceService $invoiceService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Post a cash payment against a payment slip with row locking and idempotency.
     */
    public function collectCash(PaymentSlip $slip, array $data, User $actor, ?string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?: ($data['idempotency_key'] ?? Str::uuid()->toString());

        // 1. Check idempotency record
        $existing = PaymentIdempotency::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $currentPayloadHash = hash('sha256', json_encode($data));
            if ($existing->request_hash !== $currentPayloadHash) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['IDEMPOTENCY_KEY_REUSE_MISMATCH: The idempotency key has already been used with different parameters.'],
                ]);
            }

            return $existing->response_body;
        }

        $result = DB::transaction(function () use ($slip, $data, $actor, $idempotencyKey) {
            // 2. Validate active cashier shift
            $shift = $this->shiftService->getActiveShift($actor);
            if (!$shift) {
                throw ValidationException::withMessages([
                    'cashier_shift_id' => ['CASHIER_SHIFT_REQUIRED: An active open cashier shift is required to accept cash collections.'],
                ]);
            }

            // 3. Strict Deterministic Row Locking Order:
            // 1) invoices, 2) invoice_items, 3) payment_slips, 4) cashier_shifts
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::query()->where('id', $slip->invoice_id)->lockForUpdate()->firstOrFail();

            $lockedItems = InvoiceItem::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            /** @var PaymentSlip $lockedSlip */
            $lockedSlip = PaymentSlip::query()->where('id', $slip->id)->lockForUpdate()->firstOrFail();

            /** @var CashierShift $lockedShift */
            $lockedShift = CashierShift::query()->where('id', $shift->id)->lockForUpdate()->firstOrFail();

            // Validate slip status
            if ($lockedSlip->status === PaymentSlipStatus::COLLECTED) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_ALREADY_COLLECTED: This payment slip has already been fully collected.'],
                ]);
            }

            if ($lockedSlip->status === PaymentSlipStatus::VOIDED) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_VOIDED: Cannot accept cash against a voided payment slip.'],
                ]);
            }

            if ($lockedSlip->status === PaymentSlipStatus::SUPERSEDED) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_SUPERSEDED: Cannot accept cash against a superseded payment slip.'],
                ]);
            }

            if ($lockedSlip->expires_at && Carbon::now()->isAfter($lockedSlip->expires_at)) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_EXPIRED: Payment slip has expired. Please issue a new slip.'],
                ]);
            }

            $amountReceived = isset($data['amount_received']) ? (float) $data['amount_received'] : (float) $lockedSlip->remaining_amount;
            if ($amountReceived <= 0.0) {
                throw ValidationException::withMessages([
                    'amount_received' => ['AMOUNT_INVALID: Collected cash amount must be greater than zero.'],
                ]);
            }

            $verifiedCollected = $this->invoiceService->getVerifiedCollectedAmount($lockedInvoice->id);
            $invoiceBalance = (float) bcsub((string) $lockedInvoice->net_amount, (string) $verifiedCollected, 2);
            $slipRemaining = (float) $lockedSlip->remaining_amount;

            if ($amountReceived > $slipRemaining || $amountReceived > $invoiceBalance) {
                throw ValidationException::withMessages([
                    'amount_received' => ['OVERPAYMENT_NOT_ALLOWED: Received amount exceeds outstanding slip or invoice balance.'],
                ]);
            }

            $cashTendered = isset($data['cash_tendered_amount']) ? (float) $data['cash_tendered_amount'] : $amountReceived;
            if ($cashTendered < $amountReceived) {
                throw ValidationException::withMessages([
                    'cash_tendered_amount' => ['INSUFFICIENT_TENDERED_CASH: Cash tendered cannot be less than the amount received.'],
                ]);
            }
            $changeReturned = round($cashTendered - $amountReceived, 2);

            // 4. Create Payment (POSTED)
            $paymentRef = $this->sequenceService->nextNumber('TXN', 'payments', 'payment_reference');
            $payment = Payment::create([
                'payment_reference' => $paymentRef,
                'payment_slip_id' => $lockedSlip->id,
                'invoice_id' => $lockedInvoice->id,
                'student_id' => $lockedInvoice->student_id,
                'enrollment_id' => $lockedInvoice->enrollment_id,
                'academic_period_id' => $lockedInvoice->academic_period_id,
                'cashier_shift_id' => $lockedShift->id,
                'payment_method' => 'CASH',
                'status' => PaymentStatus::POSTED,
                'amount_received' => $amountReceived,
                'allocated_amount' => $amountReceived,
                'cash_tendered_amount' => $cashTendered,
                'change_returned_amount' => $changeReturned,
                'collected_by' => $actor->id,
                'collected_at' => now(),
                'counter_code' => $lockedShift->counter_code,
                'remarks' => $data['remarks'] ?? null,
                'admission_approved_triggered' => false,
                'idempotency_key' => $idempotencyKey,
                'request_id' => request()->header('X-Request-Id', Str::uuid()->toString()),
            ]);

            // 5. Compute line-item balances from historical allocations
            $allocatedItemSums = DB::table('payment_allocations')
                ->where('invoice_id', $lockedInvoice->id)
                ->select('invoice_item_id', DB::raw("SUM(CASE WHEN direction = 'CREDIT' THEN allocated_amount ELSE -allocated_amount END) as total_paid"))
                ->groupBy('invoice_item_id')
                ->pluck('total_paid', 'invoice_item_id')
                ->all();

            $remainingToAllocate = $amountReceived;
            $sortedItems = $lockedItems->sort(function ($a, $b) {
                if ($a->admission_required !== $b->admission_required) {
                    return $b->admission_required <=> $a->admission_required;
                }
                return $a->id <=> $b->id;
            });

            $itemAllocationsMap = [];
            foreach ($sortedItems as $item) {
                if ($remainingToAllocate <= 0.0) {
                    break;
                }

                $alreadyPaid = (float) ($allocatedItemSums[$item->id] ?? 0.00);
                $itemBal = max(0.00, round((float) $item->net_amount - $alreadyPaid, 2));

                if ($itemBal <= 0.0) {
                    continue;
                }

                $allocAmount = min($remainingToAllocate, $itemBal);
                $remainingToAllocate -= $allocAmount;

                $itemBalanceBefore = $itemBal;
                $itemBalanceAfter = round($itemBal - $allocAmount, 2);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'payment_reversal_id' => null,
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_item_id' => $item->id,
                    'direction' => 'CREDIT',
                    'allocated_amount' => $allocAmount,
                    'admission_required' => (bool) $item->admission_required,
                    'item_balance_before' => $itemBalanceBefore,
                    'item_balance_after' => $itemBalanceAfter,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $itemAllocationsMap[$item->id] = (string) number_format($allocAmount, 2, '.', '');
            }

            // 6. Sync D06 Billing Event
            $eventId = Str::uuid()->toString();
            $this->billingLedgerReadService->consumePaymentAllocated(
                eventId: $eventId,
                invoiceId: $lockedInvoice->id,
                allocatedAmount: (string) number_format($amountReceived, 2, '.', ''),
                itemAllocations: $itemAllocationsMap
            );

            // 7. Update PaymentSlip
            $slipCollected = round((float) $lockedSlip->collected_amount + $amountReceived, 2);
            $slipRemaining = round((float) $lockedSlip->slip_amount - $slipCollected, 2);
            $slipStatus = ($slipRemaining <= 0.0001) ? PaymentSlipStatus::COLLECTED : PaymentSlipStatus::PARTIALLY_COLLECTED;

            $lockedSlip->update([
                'collected_amount' => $slipCollected,
                'remaining_amount' => max(0.00, $slipRemaining),
                'status' => $slipStatus,
            ]);

            // 8. Update CashierShift
            $lockedShift->increment('expected_cash_amount', $amountReceived);

            // 9. Issue PaymentReceipt (RC-YYYY-NNNNNN)
            $receiptNumber = $this->sequenceService->nextNumber('RC', 'payment_receipts', 'receipt_number');
            $receiptTokenData = $this->tokenService->generateToken('receipt', 0, $receiptNumber);

            $receiptSnapshot = [
                'receipt_number' => $receiptNumber,
                'payment_reference' => $paymentRef,
                'slip_number' => $lockedSlip->slip_number,
                'invoice_number' => $lockedInvoice->invoice_number,
                'student' => [
                    'id' => $lockedInvoice->student_id,
                    'student_code' => $lockedInvoice->student?->student_code,
                    'name_bn' => $lockedInvoice->student?->name_bn,
                    'name_en' => $lockedInvoice->student?->name_en,
                ],
                'amount_received' => (string) number_format($amountReceived, 2, '.', ''),
                'cash_tendered' => (string) number_format($cashTendered, 2, '.', ''),
                'change_returned' => (string) number_format($changeReturned, 2, '.', ''),
                'collected_by' => $actor->name,
                'counter_code' => $lockedShift->counter_code,
                'collected_at' => now()->toIso8601String(),
            ];

            $receipt = PaymentReceipt::create([
                'receipt_number' => $receiptNumber,
                'payment_id' => $payment->id,
                'payment_slip_id' => $lockedSlip->id,
                'invoice_id' => $lockedInvoice->id,
                'student_id' => $lockedInvoice->student_id,
                'status' => ReceiptStatus::VALID,
                'receipt_amount' => $amountReceived,
                'verification_token_hash' => $receiptTokenData['hash'],
                'verification_token_encrypted' => $receiptTokenData['encrypted'],
                'snapshot_payload' => $receiptSnapshot,
                'print_count' => 1,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            // 10. Check D04 Admission Gate: Trigger approval if enrollment admission items are settled
            if ($lockedInvoice->enrollment_id) {
                $triggered = $this->admissionClearanceService->checkAndTriggerApproval(
                    enrollmentId: $lockedInvoice->enrollment_id,
                    paymentReference: $paymentRef,
                    actorId: $actor->id
                );

                if ($triggered) {
                    $payment->update(['admission_approved_triggered' => true]);
                }
            }

            // 11. Enqueue Guardian SMS Notification
            $notification = $this->notificationService->enqueuePaymentReceiptSms($payment, $receipt, $actor);

            // 12. Write PaymentOutbox event
            PaymentOutbox::create([
                'event_id' => Str::uuid()->toString(),
                'event_type' => 'PaymentPosted',
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'payload' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $paymentRef,
                    'receipt_number' => $receiptNumber,
                    'invoice_id' => $lockedInvoice->id,
                    'amount_received' => $amountReceived,
                ],
                'status' => 'PENDING',
                'available_at' => now(),
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'payment.posted',
                actorId: $actor->id,
                targetType: Payment::class,
                targetId: $payment->id,
                payload: [
                    'payment_reference' => $paymentRef,
                    'amount_received' => $amountReceived,
                    'receipt_number' => $receiptNumber,
                ]
            );

            return [
                'success' => true,
                'payment' => [
                    'id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'amount_received' => (string) $payment->amount_received,
                    'cash_tendered_amount' => (string) $payment->cash_tendered_amount,
                    'change_returned_amount' => (string) $payment->change_returned_amount,
                    'status' => $payment->status->value,
                    'admission_approved_triggered' => $payment->admission_approved_triggered,
                ],
                'receipt' => [
                    'id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'receipt_amount' => (string) $receipt->receipt_amount,
                    'status' => $receipt->status->value,
                    'verification_token' => $receiptTokenData['token'],
                ],
                'slip' => [
                    'id' => $lockedSlip->id,
                    'slip_number' => $lockedSlip->slip_number,
                    'status' => $lockedSlip->status->value,
                    'remaining_amount' => (string) $lockedSlip->remaining_amount,
                ],
                'notification' => [
                    'id' => $notification->id,
                    'status' => $notification->status->value,
                    'recipient_phone_masked' => $notification->recipient_phone_masked,
                ],
            ];
        });

        // Store Idempotency
        PaymentIdempotency::create([
            'idempotency_key' => $idempotencyKey,
            'operation' => 'cash_collection',
            'actor_id' => $actor->id,
            'request_hash' => hash('sha256', json_encode($data)),
            'response_code' => 201,
            'response_body' => $result,
            'resource_type' => 'Payment',
            'resource_id' => $result['payment']['id'] ?? null,
            'expires_at' => now()->addDays(7),
        ]);

        return $result;
    }
}
