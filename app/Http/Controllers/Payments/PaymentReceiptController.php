<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\AuditTrail\AuditLogService;
use App\Services\Payments\SlipVerificationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReceiptController extends Controller
{
    public function __construct(
        protected SlipVerificationTokenService $tokenService,
        protected AuditLogService $auditLogService
    ) {}

    public function showByPayment(Payment $payment): JsonResponse
    {
        $this->authorize('payments.receipts.read');

        $receipt = PaymentReceipt::with(['student', 'invoice.items', 'payment.collector', 'paymentSlip'])
            ->where('payment_id', $payment->id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $receipt,
        ]);
    }

    public function show(PaymentReceipt $receipt): JsonResponse
    {
        $this->authorize('payments.receipts.read');

        return response()->json([
            'success' => true,
            'data' => $receipt->load(['student', 'invoice.items', 'payment.collector', 'paymentSlip']),
        ]);
    }

    public function printPayload(PaymentReceipt $receipt, Request $request): JsonResponse
    {
        $this->authorize('payments.receipts.read');

        $receipt->increment('print_count');
        $receipt->update(['last_printed_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'receipt_number' => $receipt->receipt_number,
                'status' => $receipt->status->value,
                'receipt_amount' => (string) $receipt->receipt_amount,
                'print_count' => $receipt->print_count,
                'last_printed_at' => $receipt->last_printed_at?->toIso8601String(),
                'last_reprint_reason' => $receipt->last_reprint_reason,
                'verification_token_hash' => $receipt->verification_token_hash,
                'snapshot' => $receipt->snapshot_payload,
            ],
        ]);
    }

    public function reprint(PaymentReceipt $receipt, Request $request): JsonResponse
    {
        $this->authorize('payments.receipts.read');

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $receipt->increment('print_count');
        $receipt->update([
            'last_printed_at' => now(),
            'last_reprint_reason' => $validated['reason'],
        ]);

        $this->auditLogService->log(
            action: 'payment_receipt.reprinted',
            actorId: $request->user()->id,
            targetType: PaymentReceipt::class,
            targetId: $receipt->id,
            payload: [
                'receipt_number' => $receipt->receipt_number,
                'print_count' => $receipt->print_count,
                'reason' => $validated['reason'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt reprint recorded.',
            'data' => $receipt->fresh(),
        ]);
    }
}

