<?php

namespace App\Services\Billing;

use App\Enums\InstallmentPlanStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingDomainException;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InstallmentPlanService
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {}

    public function getPlan(int $invoiceId): array
    {
        $invoice = Invoice::with(['activeInstallmentPlan.items', 'student'])->find($invoiceId);
        if (!$invoice) {
            throw BillingDomainException::notFound('INVOICE_NOT_FOUND', "Invoice #{$invoiceId} not found.");
        }

        $verifiedCollected = $this->invoiceService->getVerifiedCollectedAmount($invoice->id);
        $netAmount = number_format((float) $invoice->net_amount, 2, '.', '');
        $uncoveredBalance = bcsub($netAmount, $verifiedCollected, 2);

        $plan = $invoice->activeInstallmentPlan;

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'student_record_id' => $invoice->student_id,
            'student_public_id' => $invoice->student?->student_id,
            'student_name_bn' => $invoice->student?->name_bn,
            'student_name_en' => $invoice->student?->name_en,
            'net_amount' => $netAmount,
            'verified_collected_amount' => $verifiedCollected,
            'uncovered_balance' => $uncoveredBalance,
            'plan' => $plan ? [
                'id' => $plan->id,
                'status' => $plan->status->value ?? $plan->status,
                'version' => $plan->version,
                'lock_version' => $plan->lock_version,
                'notes' => $plan->notes,
                'items' => $plan->items->map(fn (InstallmentScheduleItem $item) => [
                    'id' => $item->id,
                    'installment_no' => $item->installment_no,
                    'due_date' => $item->due_date->format('Y-m-d'),
                    'scheduled_amount' => number_format((float) $item->scheduled_amount, 2, '.', ''),
                    'status' => $item->status,
                ])->values()->all(),
            ] : null,
        ];
    }

    /**
     * Create or replace the active installment schedule for an invoice.
     *
     * Invariants:
     * - Due dates must be strictly ascending (VAL-15, C40)
     * - Sum of scheduled_amount must equal uncovered invoice balance (VAL-15, C39)
     * - Never alters actual settled balance or approves D04 admission (C41, C42)
     */
    public function replacePlan(int $invoiceId, array $data, int $actorId): array
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw BillingDomainException::validation('EMPTY_INSTALLMENT_ITEMS', 'At least one installment schedule item is required.');
        }

        return DB::transaction(function () use ($invoiceId, $data, $items, $actorId) {
            $invoice = Invoice::with('activeInstallmentPlan')->lockForUpdate()->find($invoiceId);
            if (!$invoice) {
                throw BillingDomainException::notFound('INVOICE_NOT_FOUND', "Invoice #{$invoiceId} not found.");
            }

            if ($invoice->status === InvoiceStatus::VOID) {
                throw BillingDomainException::conflict('INVOICE_STATE_CONFLICT', 'Cannot schedule installments for a voided invoice.');
            }

            $activePlan = $invoice->activeInstallmentPlan;
            if ($activePlan && isset($data['lock_version'])) {
                $expectedVersion = (int) $data['lock_version'];
                if ($expectedVersion !== $activePlan->lock_version && $expectedVersion !== $invoice->lock_version) {
                    throw BillingDomainException::staleVersion($activePlan->lock_version, $expectedVersion);
                }
            }

            $verifiedCollected = $this->invoiceService->getVerifiedCollectedAmount($invoice->id);
            $netAmount = number_format((float) $invoice->net_amount, 2, '.', '');
            $uncoveredBalance = bcsub($netAmount, $verifiedCollected, 2);

            $sumScheduled = '0.00';
            $prevDueDate = null;
            $preparedItems = [];

            foreach ($items as $idx => $item) {
                $dueDate = trim((string) ($item['due_date'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                    throw BillingDomainException::validation(
                        'INVALID_INSTALLMENT_DATE',
                        "Invalid due_date format at installment #" . ($idx + 1) . '.'
                    );
                }

                if ($prevDueDate !== null && $dueDate <= $prevDueDate) {
                    throw BillingDomainException::validation(
                        'NON_ASCENDING_INSTALLMENT_DATES',
                        "Installment due dates must be strictly ascending. Date '{$dueDate}' is not after '{$prevDueDate}'."
                    );
                }
                $prevDueDate = $dueDate;

                $amountRaw = (string) ($item['scheduled_amount'] ?? '');
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountRaw)) {
                    throw BillingDomainException::validation(
                        'INVALID_MONETARY_AMOUNT',
                        "Invalid scheduled_amount '{$amountRaw}' at installment #" . ($idx + 1) . '.'
                    );
                }

                $formattedAmt = number_format((float) $amountRaw, 2, '.', '');
                if (bccomp($formattedAmt, '0.00', 2) <= 0) {
                    throw BillingDomainException::validation(
                        'INVALID_MONETARY_AMOUNT',
                        'Each installment scheduled_amount must be greater than zero.'
                    );
                }

                $sumScheduled = bcadd($sumScheduled, $formattedAmt, 2);

                $preparedItems[] = [
                    'installment_no' => $idx + 1,
                    'due_date' => $dueDate,
                    'scheduled_amount' => $formattedAmt,
                    'status' => 'SCHEDULED',
                ];
            }

            if (bccomp($sumScheduled, $uncoveredBalance, 2) !== 0) {
                throw BillingDomainException::validation(
                    'INVALID_INSTALLMENT_TOTAL',
                    "Sum of scheduled installments (৳{$sumScheduled}) must equal the uncovered invoice balance (৳{$uncoveredBalance}).",
                    [
                        'scheduled_sum' => $sumScheduled,
                        'uncovered_balance' => $uncoveredBalance,
                    ]
                );
            }

            $maxVersion = InstallmentPlan::where('invoice_id', $invoice->id)->max('version') ?? 0;

            if ($activePlan) {
                $activePlan->update([
                    'status' => InstallmentPlanStatus::REPLACED,
                    'lock_version' => $activePlan->lock_version + 1,
                ]);
            }

            $newPlan = InstallmentPlan::create([
                'invoice_id' => $invoice->id,
                'status' => InstallmentPlanStatus::ACTIVE,
                'version' => $maxVersion + 1,
                'lock_version' => 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($preparedItems as $pi) {
                InstallmentScheduleItem::create([
                    'installment_plan_id' => $newPlan->id,
                    'installment_no' => $pi['installment_no'],
                    'due_date' => $pi['due_date'],
                    'scheduled_amount' => $pi['scheduled_amount'],
                    'status' => $pi['status'],
                ]);
            }

            return $this->getPlan($invoice->id);
        });
    }
}
