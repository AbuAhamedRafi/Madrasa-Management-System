<?php

namespace App\Services\Billing;

use App\Enums\AdjustmentState;
use App\Enums\AdjustmentType;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingDomainException;
use App\Models\BillingOutbox;
use App\Models\FeeAdjustment;
use App\Models\FeeAdjustmentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeeAdjustmentService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {}

    public function listRequests(array $filters = []): LengthAwarePaginator
    {
        $query = FeeAdjustmentRequest::with([
            'invoiceItem.invoice.student',
            'invoiceItem.feeCategory',
            'requester',
            'decider',
            'adjustment',
        ]);

        if (!empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        if (!empty($filters['adjustment_type'])) {
            $query->where('adjustment_type', $filters['adjustment_type']);
        }
        if (!empty($filters['invoice_id'])) {
            $invId = (int) $filters['invoice_id'];
            $query->whereHas('invoiceItem', fn ($q) => $q->where('invoice_id', $invId));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function getRequestDetail(int $id): array
    {
        $req = FeeAdjustmentRequest::with([
            'invoiceItem.invoice.student',
            'invoiceItem.feeCategory',
            'requester',
            'decider',
            'adjustment',
        ])->find($id);

        if (!$req) {
            throw BillingDomainException::notFound('ADJUSTMENT_REQUEST_NOT_FOUND', "Adjustment request #{$id} not found.");
        }

        return $this->formatRequestPayload($req);
    }

    /**
     * Request a line-level discount, scholarship, or waiver.
     * Does not alter invoice amounts until approved (C44).
     */
    public function requestAdjustment(array $data, int $actorId): array
    {
        $itemId = (int) ($data['invoice_item_id'] ?? 0);
        $item = InvoiceItem::with('invoice')->find($itemId);
        if (!$item || !$item->invoice) {
            throw BillingDomainException::notFound('INVOICE_ITEM_NOT_FOUND', "Invoice item #{$itemId} not found.");
        }

        if ($item->invoice->status === InvoiceStatus::VOID) {
            throw BillingDomainException::conflict('INVOICE_STATE_CONFLICT', 'Cannot request an adjustment on a voided invoice.');
        }

        $amountRaw = (string) ($data['requested_amount'] ?? '');
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountRaw)) {
            throw BillingDomainException::validation('INVALID_MONETARY_AMOUNT', 'Invalid requested_amount format.');
        }

        $requestedAmount = number_format((float) $amountRaw, 2, '.', '');
        if (bccomp($requestedAmount, '0.00', 2) <= 0) {
            throw BillingDomainException::validation('INVALID_MONETARY_AMOUNT', 'Requested adjustment amount must be greater than zero.');
        }

        $itemRemainingNet = number_format((float) $item->net_amount, 2, '.', '');
        if (bccomp($requestedAmount, $itemRemainingNet, 2) > 0) {
            throw BillingDomainException::validation(
                'WAIVER_EXCEEDS_BALANCE',
                "Requested adjustment (৳{$requestedAmount}) exceeds the remaining item net liability (৳{$itemRemainingNet}).",
                [
                    'requested_amount' => $requestedAmount,
                    'remaining_item_net' => $itemRemainingNet,
                ]
            );
        }

        $type = isset($data['adjustment_type'])
            ? (AdjustmentType::tryFrom($data['adjustment_type']) ?? AdjustmentType::DISCOUNT)
            : AdjustmentType::DISCOUNT;

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw BillingDomainException::validation('REASON_REQUIRED', 'A valid reason is required for adjustment requests.');
        }

        $req = FeeAdjustmentRequest::create([
            'invoice_item_id' => $item->id,
            'adjustment_type' => $type,
            'requested_amount' => $requestedAmount,
            'reason' => $reason,
            'supporting_reference' => $data['supporting_reference'] ?? null,
            'state' => AdjustmentState::PENDING,
            'requested_by' => $actorId,
            'lock_version' => 1,
        ]);

        return $this->getRequestDetail($req->id);
    }

    /**
     * Approve and atomically apply a fee adjustment request.
     *
     * Enforces:
     * - Separation of duties: requester != approver by default (VAL-16, C45)
     * - Balance guard: requested_amount <= remaining item net (VAL-16, C46)
     * - Never directly approves D04 admission (C47)
     */
    public function approveAdjustment(int $id, array $data, int $actorId): array
    {
        return DB::transaction(function () use ($id, $data, $actorId) {
            $req = FeeAdjustmentRequest::with('adjustment')->lockForUpdate()->find($id);
            if (!$req) {
                throw BillingDomainException::notFound('ADJUSTMENT_REQUEST_NOT_FOUND', "Adjustment request #{$id} not found.");
            }

            if ($req->state === AdjustmentState::APPROVED || $req->state === AdjustmentState::APPLIED) {
                return $this->getRequestDetail($req->id);
            }

            if ($req->state === AdjustmentState::REJECTED) {
                throw BillingDomainException::conflict(
                    'ADJUSTMENT_STATE_CONFLICT',
                    'Cannot approve a rejected adjustment request.'
                );
            }

            if (isset($data['request_version']) && (int) $data['request_version'] !== $req->lock_version) {
                throw BillingDomainException::staleVersion($req->lock_version, (int) $data['request_version']);
            }

            // Separation of duties check (VAL-16, C45)
            $allowSelfApproval = filter_var($data['allow_self_approval_policy'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ((int) $req->requested_by === $actorId && !$allowSelfApproval) {
                throw BillingDomainException::forbidden(
                    'SEPARATION_OF_DUTIES_VIOLATION',
                    'Requesting staff cannot approve their own waiver or discount request.'
                );
            }

            $item = InvoiceItem::lockForUpdate()->find($req->invoice_item_id);
            if (!$item) {
                throw BillingDomainException::notFound('INVOICE_ITEM_NOT_FOUND', 'Target invoice item not found.');
            }

            $invoice = Invoice::with('items')->lockForUpdate()->find($item->invoice_id);
            if (!$invoice || $invoice->status === InvoiceStatus::VOID) {
                throw BillingDomainException::conflict('INVOICE_STATE_CONFLICT', 'Cannot apply adjustment to a voided invoice.');
            }

            $requestedAmount = number_format((float) $req->requested_amount, 2, '.', '');
            $currentItemNet = number_format((float) $item->net_amount, 2, '.', '');

            if (bccomp($requestedAmount, $currentItemNet, 2) > 0) {
                throw BillingDomainException::validation(
                    'WAIVER_EXCEEDS_BALANCE',
                    "Adjustment amount (৳{$requestedAmount}) exceeds remaining item net balance (৳{$currentItemNet})."
                );
            }

            // Check against D07 verified collected amount on invoice
            $verifiedCollected = $this->invoiceService->getVerifiedCollectedAmount($invoice->id);
            $newInvoiceNetPreview = bcsub(number_format((float) $invoice->net_amount, 2, '.', ''), $requestedAmount, 2);
            if (bccomp($newInvoiceNetPreview, $verifiedCollected, 2) < 0) {
                throw BillingDomainException::validation(
                    'WAIVER_EXCEEDS_UNCOLLECTED_BALANCE',
                    'Adjustment would reduce invoice net below already collected D07 allocations.'
                );
            }

            // Apply to InvoiceItem
            $newItemDiscount = bcadd(number_format((float) $item->discount_amount, 2, '.', ''), $requestedAmount, 2);
            $newItemNet = bcsub(number_format((float) $item->gross_amount, 2, '.', ''), $newItemDiscount, 2);

            $item->discount_amount = $newItemDiscount;
            $item->net_amount = $newItemNet;
            $item->save();

            // Recalculate parent Invoice totals
            $freshItems = InvoiceItem::where('invoice_id', $invoice->id)->get();
            $totalGross = '0.00';
            $totalDiscount = '0.00';
            $totalNet = '0.00';
            $requiredAdmissionNet = '0.00';

            foreach ($freshItems as $fItem) {
                $totalGross = bcadd($totalGross, number_format((float) $fItem->gross_amount, 2, '.', ''), 2);
                $totalDiscount = bcadd($totalDiscount, number_format((float) $fItem->discount_amount, 2, '.', ''), 2);
                $totalNet = bcadd($totalNet, number_format((float) $fItem->net_amount, 2, '.', ''), 2);
                if ($fItem->admission_required) {
                    $requiredAdmissionNet = bcadd($requiredAdmissionNet, number_format((float) $fItem->net_amount, 2, '.', ''), 2);
                }
            }

            $invoice->gross_amount = $totalGross;
            $invoice->adjustment_amount = $totalDiscount;
            $invoice->net_amount = $totalNet;
            $invoice->lock_version += 1;
            $invoice->save();

            $snapshotAfter = [
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $item->id,
                'applied_amount' => $requestedAmount,
                'item_discount_after' => $newItemDiscount,
                'item_net_after' => $newItemNet,
                'invoice_adjustment_after' => $totalDiscount,
                'invoice_net_after' => $totalNet,
                'required_admission_net_after' => $requiredAdmissionNet,
            ];

            FeeAdjustment::create([
                'request_id' => $req->id,
                'invoice_item_id' => $item->id,
                'amount' => $requestedAmount,
                'applied_at' => now(),
                'snapshot_after' => $snapshotAfter,
            ]);

            $req->state = AdjustmentState::APPROVED;
            $req->decided_by = $actorId;
            $req->decided_at = now();
            $req->decision_notes = $data['reason'] ?? $data['decision_notes'] ?? 'Approved';
            $req->lock_version += 1;
            $req->save();

            // Record audit event in BillingOutbox
            BillingOutbox::create([
                'event_id' => Str::uuid()->toString(),
                'event_type' => 'FeeAdjustmentApproved',
                'aggregate_type' => 'FeeAdjustmentRequest',
                'aggregate_id' => $req->id,
                'dedupe_key' => "OUTBOX:ADJ_APPROVED:REQ:{$req->id}",
                'payload_json' => array_merge($snapshotAfter, [
                    'request_id' => $req->id,
                    'requested_by' => $req->requested_by,
                    'decided_by' => $actorId,
                    'reason' => $req->decision_notes,
                ]),
                'published_at' => now(),
            ]);

            // If required admission net reached 0.00 due to full waiver, emit verifiable zero-clearance evidence for D07 (C47, C61)
            if (bccomp($requiredAdmissionNet, '0.00', 2) === 0 && $invoice->status === InvoiceStatus::ISSUED) {
                $zeroKey = "OUTBOX:ZERO_CLEARANCE_EVIDENCE:INV:{$invoice->id}";
                BillingOutbox::firstOrCreate(
                    ['dedupe_key' => $zeroKey],
                    [
                        'event_id' => Str::uuid()->toString(),
                        'event_type' => 'ZeroChargeClearanceEvidenceIssued',
                        'aggregate_type' => 'Invoice',
                        'aggregate_id' => $invoice->id,
                        'payload_json' => [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'enrollment_id' => $invoice->enrollment_id,
                            'required_admission_net' => '0.00',
                            'verifiable_zero_liability' => true,
                            'cleared_via_waiver_request_id' => $req->id,
                        ],
                        'published_at' => now(),
                    ]
                );
            }

            return $this->getRequestDetail($req->id);
        });
    }

    /**
     * Reject an adjustment request with mandatory reason (C44, C57).
     */
    public function rejectAdjustment(int $id, array $data, int $actorId): array
    {
        $reason = trim((string) ($data['reason'] ?? $data['decision_notes'] ?? ''));
        if ($reason === '') {
            throw BillingDomainException::validation('DECISION_REASON_REQUIRED', 'A reason is required to reject an adjustment request.');
        }

        return DB::transaction(function () use ($id, $reason, $actorId) {
            $req = FeeAdjustmentRequest::lockForUpdate()->find($id);
            if (!$req) {
                throw BillingDomainException::notFound('ADJUSTMENT_REQUEST_NOT_FOUND', "Adjustment request #{$id} not found.");
            }

            if ($req->state === AdjustmentState::REJECTED) {
                return $this->getRequestDetail($req->id);
            }

            if ($req->state === AdjustmentState::APPROVED || $req->state === AdjustmentState::APPLIED) {
                throw BillingDomainException::conflict(
                    'ADJUSTMENT_STATE_CONFLICT',
                    'Cannot reject an already approved adjustment request.'
                );
            }

            $req->state = AdjustmentState::REJECTED;
            $req->decided_by = $actorId;
            $req->decided_at = now();
            $req->decision_notes = $reason;
            $req->lock_version += 1;
            $req->save();

            BillingOutbox::create([
                'event_id' => Str::uuid()->toString(),
                'event_type' => 'FeeAdjustmentRejected',
                'aggregate_type' => 'FeeAdjustmentRequest',
                'aggregate_id' => $req->id,
                'dedupe_key' => "OUTBOX:ADJ_REJECTED:REQ:{$req->id}",
                'payload_json' => [
                    'request_id' => $req->id,
                    'invoice_item_id' => $req->invoice_item_id,
                    'requested_amount' => (string) $req->requested_amount,
                    'requested_by' => $req->requested_by,
                    'decided_by' => $actorId,
                    'reason' => $reason,
                ],
                'published_at' => now(),
            ]);

            return $this->getRequestDetail($req->id);
        });
    }

    private function formatRequestPayload(FeeAdjustmentRequest $req): array
    {
        return [
            'id' => $req->id,
            'invoice_item_id' => $req->invoice_item_id,
            'invoice_id' => $req->invoiceItem?->invoice_id,
            'invoice_number' => $req->invoiceItem?->invoice?->invoice_number,
            'student_record_id' => $req->invoiceItem?->invoice?->student_id,
            'student_public_id' => $req->invoiceItem?->invoice?->student?->student_id,
            'student_name_bn' => $req->invoiceItem?->invoice?->student?->name_bn,
            'student_name_en' => $req->invoiceItem?->invoice?->student?->name_en,
            'item_description_bn' => $req->invoiceItem?->description_bn,
            'item_description_en' => $req->invoiceItem?->description_en,
            'item_gross_amount' => $req->invoiceItem ? number_format((float) $req->invoiceItem->gross_amount, 2, '.', '') : '0.00',
            'item_net_amount' => $req->invoiceItem ? number_format((float) $req->invoiceItem->net_amount, 2, '.', '') : '0.00',
            'adjustment_type' => $req->adjustment_type->value ?? $req->adjustment_type,
            'requested_amount' => number_format((float) $req->requested_amount, 2, '.', ''),
            'applied_amount' => $req->adjustment ? number_format((float) $req->adjustment->amount, 2, '.', '') : '0.00',
            'reason' => $req->reason,
            'supporting_reference' => $req->supporting_reference,
            'state' => $req->state->value ?? $req->state,
            'status' => $req->state->value ?? $req->state,
            'requested_by' => $req->requested_by,
            'requester_name' => $req->requester?->name_en,
            'decided_by' => $req->decided_by,
            'decider_name' => $req->decider?->name_en,
            'decided_at' => $req->decided_at?->toIso8601String(),
            'decision_notes' => $req->decision_notes,
            'lock_version' => $req->lock_version,
            'created_at' => $req->created_at?->toIso8601String(),
        ];
    }
}
