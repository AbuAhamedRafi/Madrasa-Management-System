<?php

namespace App\Services\Billing;

use App\Enums\EnrollmentStatus;
use App\Enums\InstallmentPlanStatus;
use App\Enums\InvoiceSourceType;
use App\Enums\InvoiceStatus;
use App\Enums\PeriodState;
use App\Exceptions\BillingDomainException;
use App\Models\BillingEventInbox;
use App\Models\BillingOutbox;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Admissions\EnrollmentBillingStateService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private readonly EnrollmentBillingStateService $enrollmentBillingStateService
    ) {}

    public function listInvoices(array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::with([
            'student',
            'enrollment.offering.programClass',
            'period.program',
            'items.feeCategory',
        ]);

        if (!empty($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }
        if (!empty($filters['enrollment_id'])) {
            $query->where('enrollment_id', (int) $filters['enrollment_id']);
        }
        if (!empty($filters['academic_period_id'])) {
            $query->where('academic_period_id', (int) $filters['academic_period_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        if (!empty($filters['search'])) {
            $s = trim($filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'ilike', "%{$s}%")
                  ->orWhereHas('student', function ($sq) use ($s) {
                      $sq->where('student_id', 'ilike', "%{$s}%")
                         ->orWhere('name_en', 'ilike', "%{$s}%")
                         ->orWhere('name_bn', 'ilike', "%{$s}%");
                  });
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function getInvoiceDetail(int $id): array
    {
        $invoice = Invoice::with([
            'student',
            'enrollment.offering.programClass',
            'enrollment.section',
            'period.program',
            'feeStructure',
            'items.feeCategory',
            'items.adjustments',
            'activeInstallmentPlan.items',
            'creator',
            'voider',
        ])->find($id);

        if (!$invoice) {
            throw BillingDomainException::notFound('INVOICE_NOT_FOUND', "Invoice #{$id} not found.");
        }

        return $this->formatInvoicePayload($invoice);
    }

    /**
     * Pure read-only preview of a manual/ad-hoc invoice. Zero database writes.
     */
    public function previewManualInvoice(array $data): array
    {
        $enrollment = Enrollment::with(['student', 'period', 'offering.programClass'])->find((int) ($data['enrollment_id'] ?? 0));
        if (!$enrollment) {
            throw BillingDomainException::notFound('ENROLLMENT_NOT_FOUND', 'Target enrollment not found.');
        }

        $this->assertEnrollmentEligibleForBilling($enrollment);

        $preparedItems = $this->validateAndComputeLines($data['items'] ?? []);

        $grossTotal = '0.00';
        $requiredAdmissionTotal = '0.00';

        foreach ($preparedItems as $line) {
            $grossTotal = bcadd($grossTotal, $line['gross_amount'], 2);
            if ($line['admission_required']) {
                $requiredAdmissionTotal = bcadd($requiredAdmissionTotal, $line['net_amount'], 2);
            }
        }

        return [
            'enrollment_id' => $enrollment->id,
            'student_record_id' => $enrollment->student_id,
            'student_public_id' => $enrollment->student?->student_id,
            'academic_period_id' => $enrollment->academic_period_id,
            'currency' => 'BDT',
            'gross_amount' => $grossTotal,
            'adjustment_amount' => '0.00',
            'net_amount' => $grossTotal,
            'required_admission_net' => $requiredAdmissionTotal,
            'items' => $preparedItems,
        ];
    }

    /**
     * Create a draft invoice (or auto-issue if requested).
     */
    public function createDraftInvoice(array $data, int $actorId): array
    {
        $enrollment = Enrollment::with(['student', 'period'])->find((int) ($data['enrollment_id'] ?? 0));
        if (!$enrollment) {
            throw BillingDomainException::notFound('ENROLLMENT_NOT_FOUND', 'Target enrollment not found.');
        }

        $this->assertEnrollmentEligibleForBilling($enrollment);

        if ($enrollment->period && $enrollment->period->state === PeriodState::CLOSED) {
            throw BillingDomainException::validation(
                'BILLING_PERIOD_CLOSED',
                'Cannot create an invoice for a closed academic period.'
            );
        }

        $sourceType = isset($data['source_type'])
            ? (InvoiceSourceType::tryFrom($data['source_type']) ?? InvoiceSourceType::MANUAL)
            : InvoiceSourceType::MANUAL;

        $sourceKey = !empty($data['source_key'])
            ? trim($data['source_key'])
            : "MANUAL:ENR:{$enrollment->id}:" . Str::uuid()->toString();

        if (Invoice::where('source_key', $sourceKey)->exists()) {
            throw BillingDomainException::conflict(
                'INVOICE_ALREADY_EXISTS',
                "An invoice with source_key '{$sourceKey}' already exists.",
                ['source_key' => $sourceKey]
            );
        }

        $preparedItems = $this->validateAndComputeLines($data['items'] ?? [], (bool) ($data['zero_charge_policy'] ?? false));

        $grossTotal = '0.00';
        foreach ($preparedItems as $line) {
            $grossTotal = bcadd($grossTotal, $line['gross_amount'], 2);
        }

        $dueAt = $data['due_at'] ?? Carbon::now('Asia/Dhaka')->addDays(14)->format('Y-m-d');

        $invoice = DB::transaction(function () use ($enrollment, $sourceType, $sourceKey, $grossTotal, $data, $preparedItems, $dueAt, $actorId) {
            $year = (int) Carbon::now('Asia/Dhaka')->format('Y');
            $invoiceNumber = $this->generateNextInvoiceNumber($year);

            $inv = Invoice::create([
                'invoice_number' => $invoiceNumber,
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'academic_period_id' => $enrollment->academic_period_id,
                'fee_structure_id' => $data['fee_structure_id'] ?? null,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'currency' => 'BDT',
                'gross_amount' => $grossTotal,
                'adjustment_amount' => '0.00',
                'net_amount' => $grossTotal,
                'zero_charge_policy' => (bool) ($data['zero_charge_policy'] ?? false),
                'status' => InvoiceStatus::DRAFT,
                'due_at' => $dueAt,
                'notes' => $data['notes'] ?? null,
                'lock_version' => 1,
                'created_by' => $actorId,
            ]);

            foreach ($preparedItems as $idx => $line) {
                InvoiceItem::create([
                    'invoice_id' => $inv->id,
                    'fee_category_id' => $line['fee_category_id'],
                    'fee_structure_item_id' => $line['fee_structure_item_id'] ?? null,
                    'source_version' => $line['source_version'] ?? null,
                    'description_bn' => $line['description_bn'],
                    'description_en' => $line['description_en'],
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'],
                    'gross_amount' => $line['gross_amount'],
                    'discount_amount' => '0.00',
                    'net_amount' => $line['net_amount'],
                    'admission_required' => $line['admission_required'],
                    'billing_month' => $line['billing_month'] ?? null,
                    'sort_order' => $line['sort_order'] ?? ($idx + 1),
                ]);
            }

            return $inv;
        });

        if (!empty($data['auto_issue'])) {
            return $this->issueInvoice($invoice->id, $actorId);
        }

        return $this->getInvoiceDetail($invoice->id);
    }

    /**
     * Issue a validated draft invoice.
     */
    public function issueInvoice(int $id, int $actorId): array
    {
        return DB::transaction(function () use ($id, $actorId) {
            $invoice = Invoice::with(['items', 'enrollment', 'period'])->lockForUpdate()->find($id);
            if (!$invoice) {
                throw BillingDomainException::notFound('INVOICE_NOT_FOUND', "Invoice #{$id} not found.");
            }

            if ($invoice->status === InvoiceStatus::ISSUED) {
                return $this->getInvoiceDetail($invoice->id);
            }

            if ($invoice->status === InvoiceStatus::VOID) {
                throw BillingDomainException::conflict(
                    'INVOICE_STATE_CONFLICT',
                    'Cannot issue a voided invoice.'
                );
            }

            if ($invoice->enrollment) {
                $this->assertEnrollmentEligibleForBilling($invoice->enrollment);
            }

            if ($invoice->items->isEmpty()) {
                throw BillingDomainException::validation(
                    'EMPTY_INVOICE_LINES',
                    'Cannot issue an invoice with zero charge lines.'
                );
            }

            if (
                bccomp((string) $invoice->net_amount, '0.00', 2) <= 0
                && !$invoice->zero_charge_policy
                && bccomp((string) $invoice->adjustment_amount, '0.00', 2) <= 0
            ) {
                throw BillingDomainException::validation(
                    'ZERO_CHARGE_POLICY_REQUIRED',
                    'Cannot issue a zero-amount invoice unless explicit zero_charge_policy is enabled.'
                );
            }

            $invoice->status = InvoiceStatus::ISSUED;
            $invoice->issued_at = now();
            $invoice->lock_version += 1;
            $invoice->save();

            // Record durable BillingPrepared outbox event
            $dedupeKey = "OUTBOX:BILLING_PREPARED:INV:{$invoice->id}";
            BillingOutbox::firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'event_id' => Str::uuid()->toString(),
                    'event_type' => 'BillingPrepared',
                    'aggregate_type' => 'Invoice',
                    'aggregate_id' => $invoice->id,
                    'payload_json' => [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'enrollment_id' => $invoice->enrollment_id,
                        'student_id' => $invoice->student_id,
                        'net_amount' => (string) $invoice->net_amount,
                        'zero_charge_policy' => $invoice->zero_charge_policy,
                    ],
                    'published_at' => now(),
                ]
            );

            // If zero_charge_policy or required admission charges are 0.00, emit verifiable zero-clearance evidence for D07
            $requiredNet = $this->calculateRequiredAdmissionNet($invoice);
            if ($invoice->zero_charge_policy || bccomp($requiredNet, '0.00', 2) === 0) {
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
                        ],
                        'published_at' => now(),
                    ]
                );
            }

            // Transition D04 enrollment from PENDING_BILLING -> PENDING_PAYMENT (never directly to APPROVED!)
            if ($invoice->enrollment && $invoice->enrollment->status === EnrollmentStatus::PENDING_BILLING) {
                $this->enrollmentBillingStateService->markPrepared(
                    $invoice->enrollment_id,
                    $invoice->invoice_number,
                    $actorId
                );
            }

            return $this->getInvoiceDetail($invoice->id);
        });
    }

    /**
     * Void an uncollected invoice with mandatory reason and D07 payment safeguard.
     */
    public function voidInvoice(int $id, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw BillingDomainException::validation('VOID_REASON_REQUIRED', 'A valid reason is required to void an invoice.');
        }

        return DB::transaction(function () use ($id, $reason, $actorId) {
            $invoice = Invoice::lockForUpdate()->find($id);
            if (!$invoice) {
                throw BillingDomainException::notFound('INVOICE_NOT_FOUND', "Invoice #{$id} not found.");
            }

            if ($invoice->status === InvoiceStatus::VOID) {
                return $this->getInvoiceDetail($invoice->id);
            }

            // Check D07 payment allocations safeguard (VAL-17, C30)
            $verifiedCollected = $this->getVerifiedCollectedAmount($invoice->id);
            if (bccomp($verifiedCollected, '0.00', 2) > 0) {
                throw BillingDomainException::conflict(
                    'FINANCIAL_POSTING_EXISTS',
                    'Invoice has verified D07 payment allocations and cannot be voided directly in D06. Execute D07 financial reversal first.',
                    ['verified_collected_amount' => $verifiedCollected]
                );
            }

            $invoice->status = InvoiceStatus::VOID;
            $invoice->voided_at = now();
            $invoice->void_reason = $reason;
            $invoice->voided_by = $actorId;
            $invoice->lock_version += 1;
            $invoice->save();

            // Cancel any active installment plans
            $invoice->installmentPlans()
                ->where('status', InstallmentPlanStatus::ACTIVE->value)
                ->update(['status' => InstallmentPlanStatus::CANCELLED->value]);

            // Record audit event in BillingOutbox
            BillingOutbox::create([
                'event_id' => Str::uuid()->toString(),
                'event_type' => 'InvoiceVoided',
                'aggregate_type' => 'Invoice',
                'aggregate_id' => $invoice->id,
                'dedupe_key' => "OUTBOX:INVOICE_VOIDED:INV:{$invoice->id}:" . now()->timestamp,
                'payload_json' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'enrollment_id' => $invoice->enrollment_id,
                    'void_reason' => $reason,
                    'voided_by' => $actorId,
                ],
                'published_at' => now(),
            ]);

            return $this->getInvoiceDetail($invoice->id);
        });
    }

    public function generateNextInvoiceNumber(int $year): string
    {
        $prefix = "INV-{$year}-";
        $latest = Invoice::where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->value('invoice_number');

        $nextSeq = 1;
        if ($latest && preg_match('/^INV-\d{4}-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
    }

    public function getVerifiedCollectedAmount(int $invoiceId): string
    {
        $events = BillingEventInbox::where('event_type', 'PaymentAllocated')
            ->where('aggregate_id', $invoiceId)
            ->where('status', 'COMPLETED')
            ->get();

        $total = '0.00';
        foreach ($events as $ev) {
            $amt = (string) ($ev->payload_json['allocated_amount'] ?? '0.00');
            if (preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
                $total = bcadd($total, number_format((float) $amt, 2, '.', ''), 2);
            }
        }

        return $total;
    }

    public function calculateRequiredAdmissionNet(Invoice $invoice): string
    {
        $requiredNet = '0.00';
        foreach ($invoice->items as $item) {
            if ($item->admission_required) {
                $requiredNet = bcadd($requiredNet, (string) $item->net_amount, 2);
            }
        }
        return $requiredNet;
    }

    public function formatInvoicePayload(Invoice $invoice): array
    {
        $requiredAdmissionNet = $this->calculateRequiredAdmissionNet($invoice);
        $verifiedCollected = $this->getVerifiedCollectedAmount($invoice->id);
        $netAmount = number_format((float) $invoice->net_amount, 2, '.', '');
        $outstanding = bcsub($netAmount, $verifiedCollected, 2);
        if (bccomp($outstanding, '0.00', 2) < 0) {
            $outstanding = '0.00';
        }

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'student_record_id' => $invoice->student_id,
            'student_public_id' => $invoice->student?->student_id,
            'student_name_bn' => $invoice->student?->name_bn,
            'student_name_en' => $invoice->student?->name_en,
            'enrollment_id' => $invoice->enrollment_id,
            'enrollment_status' => $invoice->enrollment?->status?->value ?? $invoice->enrollment?->status,
            'academic_period_id' => $invoice->academic_period_id,
            'academic_period_label' => $invoice->period?->label,
            'program_name_bn' => $invoice->period?->program?->name_bn,
            'program_name_en' => $invoice->period?->program?->name_en,
            'class_name_bn' => $invoice->enrollment?->offering?->programClass?->name_bn,
            'class_name_en' => $invoice->enrollment?->offering?->programClass?->name_en,
            'fee_structure_id' => $invoice->fee_structure_id,
            'source_type' => $invoice->source_type->value ?? $invoice->source_type,
            'source_key' => $invoice->source_key,
            'currency' => $invoice->currency,
            'gross_amount' => number_format((float) $invoice->gross_amount, 2, '.', ''),
            'adjustment_amount' => number_format((float) $invoice->adjustment_amount, 2, '.', ''),
            'net_amount' => $netAmount,
            'required_admission_net' => $requiredAdmissionNet,
            'verified_collected_amount' => $verifiedCollected,
            'outstanding_amount' => $outstanding,
            'zero_charge_policy' => (bool) $invoice->zero_charge_policy,
            'settlement_source' => bccomp($verifiedCollected, '0.00', 2) > 0 ? 'D07_VERIFIED' : 'NOT_CONNECTED',
            'status' => $invoice->status->value ?? $invoice->status,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->format('Y-m-d'),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'notes' => $invoice->notes,
            'lock_version' => $invoice->lock_version,
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'fee_category_id' => $item->fee_category_id,
                'fee_category_code' => $item->feeCategory?->code,
                'fee_structure_item_id' => $item->fee_structure_item_id,
                'source_version' => $item->source_version,
                'description_bn' => $item->description_bn,
                'description_en' => $item->description_en,
                'quantity' => $item->quantity,
                'unit_amount' => number_format((float) $item->unit_amount, 2, '.', ''),
                'gross_amount' => number_format((float) $item->gross_amount, 2, '.', ''),
                'discount_amount' => number_format((float) $item->discount_amount, 2, '.', ''),
                'net_amount' => number_format((float) $item->net_amount, 2, '.', ''),
                'admission_required' => (bool) $item->admission_required,
                'billing_month' => $item->billing_month?->format('Y-m-d'),
                'sort_order' => $item->sort_order,
            ])->values()->all(),
            'installment_plan' => $invoice->activeInstallmentPlan ? [
                'id' => $invoice->activeInstallmentPlan->id,
                'status' => $invoice->activeInstallmentPlan->status->value ?? $invoice->activeInstallmentPlan->status,
                'version' => $invoice->activeInstallmentPlan->version,
                'lock_version' => $invoice->activeInstallmentPlan->lock_version,
                'items' => $invoice->activeInstallmentPlan->items->map(fn ($si) => [
                    'id' => $si->id,
                    'installment_no' => $si->installment_no,
                    'due_date' => $si->due_date->format('Y-m-d'),
                    'scheduled_amount' => number_format((float) $si->scheduled_amount, 2, '.', ''),
                    'status' => $si->status,
                ])->values()->all(),
            ] : null,
        ];
    }

    public function assertEnrollmentEligibleForBilling(Enrollment $enrollment): void
    {
        $status = $enrollment->status instanceof EnrollmentStatus
            ? $enrollment->status
            : EnrollmentStatus::tryFrom((string) $enrollment->status);

        if (in_array($status, [EnrollmentStatus::CANCELLED, EnrollmentStatus::REJECTED], true)) {
            throw BillingDomainException::validation(
                'ENROLLMENT_INELIGIBLE_FOR_BILLING',
                "Cannot generate or issue an invoice for an enrollment in '{$status->value}' status."
            );
        }
    }

    private function validateAndComputeLines(array $items, bool $zeroChargePolicy = false): array
    {
        if (empty($items)) {
            throw BillingDomainException::validation('EMPTY_INVOICE_LINES', 'At least one invoice charge line is required.');
        }

        $prepared = [];
        foreach ($items as $idx => $item) {
            $category = null;
            if (!empty($item['fee_category_id'])) {
                $category = FeeCategory::find((int) $item['fee_category_id']);
            } elseif (!empty($item['fee_category_code'])) {
                $category = FeeCategory::where('code', strtoupper(trim($item['fee_category_code'])))->first();
            }

            if (!$category) {
                throw BillingDomainException::validation(
                    'INVALID_FEE_CATEGORY',
                    'Invalid fee category at invoice line ' . ($idx + 1) . '.'
                );
            }

            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
            if ($quantity < 1) {
                throw BillingDomainException::validation('INVALID_QUANTITY', 'Quantity must be at least 1.');
            }

            $unitAmountRaw = (string) ($item['unit_amount'] ?? $item['amount'] ?? '');
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $unitAmountRaw)) {
                throw BillingDomainException::validation(
                    'INVALID_MONETARY_AMOUNT',
                    "Invalid or negative unit amount '{$unitAmountRaw}' at line " . ($idx + 1) . '.'
                );
            }

            $unitAmount = number_format((float) $unitAmountRaw, 2, '.', '');
            $grossAmount = bcmul($unitAmount, (string) $quantity, 2);

            if (bccomp($grossAmount, '0.00', 2) === 0 && !$zeroChargePolicy) {
                throw BillingDomainException::validation(
                    'ZERO_CHARGE_POLICY_REQUIRED',
                    'Zero-amount invoice lines require explicit zero_charge_policy.'
                );
            }

            $prepared[] = [
                'fee_category_id' => $category->id,
                'fee_category_code' => $category->code,
                'fee_structure_item_id' => $item['fee_structure_item_id'] ?? null,
                'source_version' => $item['source_version'] ?? null,
                'description_bn' => $item['description_bn'] ?? $category->name_bn,
                'description_en' => $item['description_en'] ?? $category->name_en,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'gross_amount' => $grossAmount,
                'discount_amount' => '0.00',
                'net_amount' => $grossAmount,
                'admission_required' => (bool) ($item['admission_required'] ?? false),
                'billing_month' => $item['billing_month'] ?? null,
                'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
            ];
        }

        return $prepared;
    }
}
