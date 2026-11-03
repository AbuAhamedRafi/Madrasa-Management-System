<?php

namespace App\Services\Payments;

use App\Enums\PaymentSlipStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\User;
use App\Services\AuditTrail\AuditLogService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentSlipService
{
    public function __construct(
        protected PaymentSequenceService $sequenceService,
        protected SlipVerificationTokenService $tokenService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Issue a new unpaid payment slip for an invoice.
     */
    public function issueSlip(array $data, User $actor): PaymentSlip
    {
        return DB::transaction(function () use ($data, $actor) {
            $invoiceId = (int) $data['invoice_id'];

            // 1. Lock invoice FOR UPDATE
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('id', $invoiceId)->lockForUpdate()->first();
            if (!$invoice) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['INVOICE_NOT_FOUND'],
                ]);
            }

            // Invariant: Must be in ISSUED status
            if ($invoice->status->value !== 'ISSUED') {
                throw ValidationException::withMessages([
                    'invoice_id' => ['INVOICE_NOT_PAYABLE_STATE: Invoice status must be ISSUED.'],
                ]);
            }

            // Invariant: Balance must be > 0.00
            $balance = (float) $invoice->balance_amount;
            if ($balance <= 0.0) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['INVOICE_ZERO_BALANCE: Invoice is fully paid.'],
                ]);
            }

            // Determine slip requested amount
            $requestedAmount = isset($data['slip_amount']) ? (float) $data['slip_amount'] : $balance;
            if ($requestedAmount <= 0.0 || $requestedAmount > $balance) {
                throw ValidationException::withMessages([
                    'slip_amount' => ['SLIP_AMOUNT_INVALID: Slip amount must be between 0.01 and outstanding balance.'],
                ]);
            }

            // Check for existing active slips for this invoice
            $activeSlips = PaymentSlip::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', [PaymentSlipStatus::UNPAID->value, PaymentSlipStatus::PARTIALLY_COLLECTED->value])
                ->lockForUpdate()
                ->get();

            $supersedeExisting = !empty($data['supersede_existing']);
            if ($activeSlips->isNotEmpty() && !$supersedeExisting) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['ACTIVE_PAYMENT_SLIP_CONFLICT: An active payment slip already exists for this invoice.'],
                ]);
            }

            // Lock invoice items for snapshot
            $items = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Build snapshot payload
            $snapshotPayload = [
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount' => (string) $invoice->total_amount,
                    'paid_amount' => (string) $invoice->paid_amount,
                    'balance_amount' => (string) $invoice->balance_amount,
                    'issue_date' => $invoice->issue_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                ],
                'student' => [
                    'id' => $invoice->student_id,
                    'student_code' => $invoice->student?->student_code,
                    'name_bn' => $invoice->student?->name_bn,
                    'name_en' => $invoice->student?->name_en,
                ],
                'academic_period' => [
                    'id' => $invoice->academic_period_id,
                    'name' => $invoice->academicPeriod?->name,
                ],
                'items' => $items->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'name_bn' => $item->name_bn,
                    'gross_amount' => (string) $item->gross_amount,
                    'discount_amount' => (string) $item->discount_amount,
                    'net_amount' => (string) $item->net_amount,
                    'paid_amount' => (string) $item->paid_amount,
                    'balance_amount' => (string) $item->balance_amount,
                    'admission_required' => (bool) $item->admission_required,
                ])->toArray(),
            ];

            $slipNumber = $this->sequenceService->nextNumber('PS', 'payment_slips', 'slip_number');
            $tokenData = $this->tokenService->generateToken('slip', 0, $slipNumber);

            $expiresAt = !empty($data['expires_at'])
                ? Carbon::parse($data['expires_at'])
                : ($invoice->due_date ? Carbon::parse($invoice->due_date)->endOfDay() : null);

            $slip = PaymentSlip::create([
                'slip_number' => $slipNumber,
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'enrollment_id' => $invoice->enrollment_id,
                'academic_period_id' => $invoice->academic_period_id,
                'status' => PaymentSlipStatus::UNPAID,
                'slip_amount' => $requestedAmount,
                'collected_amount' => 0.00,
                'remaining_amount' => $requestedAmount,
                'expires_at' => $expiresAt,
                'verification_token_hash' => $tokenData['hash'],
                'verification_token_encrypted' => $tokenData['encrypted'],
                'snapshot_payload' => $snapshotPayload,
                'print_count' => 0,
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // If superseding existing active slips, mark them SUPERSEDED
            if ($activeSlips->isNotEmpty() && $supersedeExisting) {
                foreach ($activeSlips as $oldSlip) {
                    $oldSlip->update([
                        'status' => PaymentSlipStatus::SUPERSEDED,
                        'superseded_by_slip_id' => $slip->id,
                        'void_reason' => 'Superseded by new payment slip ' . $slip->slip_number,
                    ]);
                }
            }

            $this->auditLogService->log(
                action: 'payment_slip.issued',
                actorId: $actor->id,
                targetType: PaymentSlip::class,
                targetId: $slip->id,
                payload: [
                    'slip_number' => $slip->slip_number,
                    'invoice_id' => $invoice->id,
                    'slip_amount' => (string) $slip->slip_amount,
                ]
            );

            return $slip;
        });
    }

    /**
     * Void an unpaid payment slip.
     */
    public function voidSlip(PaymentSlip $slip, string $reason, User $actor): PaymentSlip
    {
        return DB::transaction(function () use ($slip, $reason, $actor) {
            /** @var PaymentSlip $lockedSlip */
            $lockedSlip = PaymentSlip::query()->where('id', $slip->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedSlip->status, [PaymentSlipStatus::COLLECTED, PaymentSlipStatus::PARTIALLY_COLLECTED])) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_ALREADY_COLLECTED: Cannot void a collected or partially collected slip.'],
                ]);
            }

            if ($lockedSlip->status === PaymentSlipStatus::VOIDED) {
                throw ValidationException::withMessages([
                    'status' => ['SLIP_ALREADY_VOIDED: Slip is already voided.'],
                ]);
            }

            $lockedSlip->update([
                'status' => PaymentSlipStatus::VOIDED,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->auditLogService->log(
                action: 'payment_slip.voided',
                actorId: $actor->id,
                targetType: PaymentSlip::class,
                targetId: $lockedSlip->id,
                payload: [
                    'slip_number' => $lockedSlip->slip_number,
                    'reason' => $reason,
                ]
            );

            return $lockedSlip;
        });
    }

    /**
     * Record a print event for the slip.
     */
    public function recordPrint(PaymentSlip $slip, User $actor): PaymentSlip
    {
        return DB::transaction(function () use ($slip) {
            /** @var PaymentSlip $lockedSlip */
            $lockedSlip = PaymentSlip::query()->where('id', $slip->id)->lockForUpdate()->firstOrFail();
            $lockedSlip->increment('print_count');
            $lockedSlip->update(['last_printed_at' => now()]);
            return $lockedSlip;
        });
    }
}

