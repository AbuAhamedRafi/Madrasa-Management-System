<?php

namespace App\Services\Payments;

use App\Enums\CashierShiftStatus;
use App\Models\CashierShift;
use App\Models\CashierShiftEvent;
use App\Models\User;
use App\Services\AuditTrail\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(
        protected PaymentSequenceService $sequenceService,
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Open a new cashier shift for the actor.
     */
    public function openShift(array $data, User $actor): CashierShift
    {
        return DB::transaction(function () use ($data, $actor) {
            // Check if cashier already has an OPEN shift
            $openShift = CashierShift::query()
                ->where('cashier_user_id', $actor->id)
                ->where('status', CashierShiftStatus::OPEN->value)
                ->lockForUpdate()
                ->first();

            if ($openShift) {
                throw ValidationException::withMessages([
                    'cashier_user_id' => ['CASHIER_SHIFT_ALREADY_OPEN: Cashier already has an active open shift.'],
                ]);
            }

            $shiftNumber = $this->sequenceService->nextNumber('CS', 'cashier_shifts', 'shift_number');
            $openingFloat = isset($data['opening_float_amount']) ? (float) $data['opening_float_amount'] : 0.00;
            $counterCode = $data['counter_code'] ?? 'MAIN_COUNTER';

            $shift = CashierShift::create([
                'shift_number' => $shiftNumber,
                'cashier_user_id' => $actor->id,
                'counter_code' => $counterCode,
                'status' => CashierShiftStatus::OPEN,
                'opened_at' => now(),
                'opening_float_amount' => $openingFloat,
                'expected_cash_amount' => $openingFloat,
            ]);

            CashierShiftEvent::create([
                'cashier_shift_id' => $shift->id,
                'event_type' => 'SHIFT_OPENED',
                'actor_id' => $actor->id,
                'payload' => [
                    'opening_float' => $openingFloat,
                    'counter_code' => $counterCode,
                ],
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'cashier_shift.opened',
                actorId: $actor->id,
                targetType: CashierShift::class,
                targetId: $shift->id,
                payload: ['shift_number' => $shiftNumber, 'opening_float' => $openingFloat]
            );

            return $shift;
        });
    }

    /**
     * Close a cashier shift with counted cash breakdown.
     */
    public function closeShift(CashierShift $shift, array $data, User $actor): CashierShift
    {
        return DB::transaction(function () use ($shift, $data, $actor) {
            /** @var CashierShift $lockedShift */
            $lockedShift = CashierShift::query()->where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if ($lockedShift->status !== CashierShiftStatus::OPEN) {
                throw ValidationException::withMessages([
                    'status' => ['CASHIER_SHIFT_NOT_OPEN: Only open shifts can be closed.'],
                ]);
            }

            // Only the cashier who opened it or an admin can close it
            if ($lockedShift->cashier_user_id !== $actor->id && !$actor->hasRole('ADMIN')) {
                throw ValidationException::withMessages([
                    'cashier_user_id' => ['FORBIDDEN_SHIFT_CLOSURE: You cannot close another cashier’s shift.'],
                ]);
            }

            $countedCash = (float) ($data['counted_cash_amount'] ?? 0.00);
            $expectedCash = (float) $lockedShift->expected_cash_amount;
            $variance = round($countedCash - $expectedCash, 2);

            if (abs($variance) > 0.0001 && empty($data['variance_reason'])) {
                throw ValidationException::withMessages([
                    'variance_reason' => ['SHIFT_VARIANCE_EXPLANATION_REQUIRED: A variance reason is required when counted cash does not match expected cash.'],
                ]);
            }

            $newStatus = abs($variance) > 0.0001 ? CashierShiftStatus::UNDER_REVIEW : CashierShiftStatus::CLOSED;

            $lockedShift->update([
                'status' => $newStatus,
                'closed_at' => now(),
                'counted_cash_amount' => $countedCash,
                'variance_amount' => $variance,
                'denomination_breakdown' => $data['denomination_breakdown'] ?? null,
                'closing_notes' => $data['closing_notes'] ?? null,
                'variance_reason' => $data['variance_reason'] ?? null,
            ]);

            CashierShiftEvent::create([
                'cashier_shift_id' => $lockedShift->id,
                'event_type' => 'SHIFT_CLOSED',
                'actor_id' => $actor->id,
                'payload' => [
                    'counted_cash' => $countedCash,
                    'expected_cash' => $expectedCash,
                    'variance' => $variance,
                ],
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'cashier_shift.closed',
                actorId: $actor->id,
                targetType: CashierShift::class,
                targetId: $lockedShift->id,
                payload: ['shift_number' => $lockedShift->shift_number, 'variance' => $variance]
            );

            return $lockedShift;
        });
    }

    /**
     * Reconcile/approve shift variance (dual control: supervisor cannot be the shift cashier).
     */
    public function reconcileShift(CashierShift $shift, array $data, User $actor): CashierShift
    {
        return DB::transaction(function () use ($shift, $data, $actor) {
            /** @var CashierShift $lockedShift */
            $lockedShift = CashierShift::query()->where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (!in_array($lockedShift->status, [CashierShiftStatus::CLOSED, CashierShiftStatus::UNDER_REVIEW])) {
                throw ValidationException::withMessages([
                    'status' => ['CASHIER_SHIFT_INVALID_STATE: Only closed or under-review shifts can be reconciled.'],
                ]);
            }

            // Dual control check: requester != approver
            if ($lockedShift->cashier_user_id === $actor->id) {
                throw ValidationException::withMessages([
                    'reconciled_by' => ['SELF_APPROVAL_FORBIDDEN: Cashiers cannot reconcile or approve their own shift variance.'],
                ]);
            }

            $lockedShift->update([
                'status' => CashierShiftStatus::RECONCILED,
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'reconciliation_notes' => $data['reconciliation_notes'] ?? null,
            ]);

            CashierShiftEvent::create([
                'cashier_shift_id' => $lockedShift->id,
                'event_type' => 'SHIFT_RECONCILED',
                'actor_id' => $actor->id,
                'payload' => [
                    'reconciled_by' => $actor->id,
                    'notes' => $data['reconciliation_notes'] ?? null,
                ],
                'created_at' => now(),
            ]);

            $this->auditLogService->log(
                action: 'cashier_shift.reconciled',
                actorId: $actor->id,
                targetType: CashierShift::class,
                targetId: $lockedShift->id,
                payload: ['shift_number' => $lockedShift->shift_number]
            );

            return $lockedShift;
        });
    }

    /**
     * Get the active open shift for a cashier.
     */
    public function getActiveShift(User $user): ?CashierShift
    {
        return CashierShift::query()
            ->where('cashier_user_id', $user->id)
            ->where('status', CashierShiftStatus::OPEN->value)
            ->first();
    }
}

