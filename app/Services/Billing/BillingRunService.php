<?php

namespace App\Services\Billing;

use App\Enums\BillingRunItemStatus;
use App\Enums\BillingRunStatus;
use App\Enums\BillingRunType;
use App\Enums\EnrollmentStatus;
use App\Enums\FeeCadence;
use App\Enums\InvoiceSourceType;
use App\Enums\PeriodState;
use App\Exceptions\BillingDomainException;
use App\Models\AcademicPeriod;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Enrollment;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BillingRunService
{
    public function __construct(
        private readonly FeeStructureService $feeStructureService,
        private readonly InvoiceService $invoiceService
    ) {}

    public function listRuns(array $filters = []): LengthAwarePaginator
    {
        $query = BillingRun::with(['period.program', 'offering.programClass', 'requester'])
            ->withCount([
                'items as total_items_count',
                'items as succeeded_count' => fn ($q) => $q->where('status', BillingRunItemStatus::SUCCEEDED->value),
                'items as skipped_count' => fn ($q) => $q->where('status', BillingRunItemStatus::SKIPPED->value),
                'items as failed_count' => fn ($q) => $q->where('status', BillingRunItemStatus::FAILED->value),
            ]);

        if (!empty($filters['academic_period_id'])) {
            $query->where('academic_period_id', (int) $filters['academic_period_id']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function getRunDetail(int $id): array
    {
        $run = BillingRun::with(['period.program', 'offering.programClass', 'requester'])
            ->withCount([
                'items as total_items_count',
                'items as pending_count' => fn ($q) => $q->where('status', BillingRunItemStatus::PENDING->value),
                'items as succeeded_count' => fn ($q) => $q->where('status', BillingRunItemStatus::SUCCEEDED->value),
                'items as skipped_count' => fn ($q) => $q->where('status', BillingRunItemStatus::SKIPPED->value),
                'items as failed_count' => fn ($q) => $q->where('status', BillingRunItemStatus::FAILED->value),
            ])
            ->find($id);

        if (!$run) {
            throw BillingDomainException::notFound('BILLING_RUN_NOT_FOUND', "Billing run #{$id} not found.");
        }

        return [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'type' => $run->type->value ?? $run->type,
            'academic_period_id' => $run->academic_period_id,
            'academic_period_label' => $run->period?->label,
            'program_name_bn' => $run->period?->program?->name_bn,
            'program_name_en' => $run->period?->program?->name_en,
            'academic_offering_id' => $run->academic_offering_id,
            'offering_class_name_bn' => $run->offering?->programClass?->name_bn,
            'offering_class_name_en' => $run->offering?->programClass?->name_en,
            'billing_month' => $run->billing_month?->format('Y-m'),
            'billing_month_date' => $run->billing_month?->format('Y-m-d'),
            'status' => $run->status->value ?? $run->status,
            'requested_by' => $run->requested_by,
            'requester_name' => $run->requester?->name_en,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'notes' => $run->notes,
            'lock_version' => $run->lock_version,
            'counts' => [
                'total' => $run->total_items_count ?? 0,
                'pending' => $run->pending_count ?? 0,
                'succeeded' => $run->succeeded_count ?? 0,
                'skipped' => $run->skipped_count ?? 0,
                'failed' => $run->failed_count ?? 0,
            ],
        ];
    }

    public function listRunItems(int $runId, array $filters = []): LengthAwarePaginator
    {
        $run = BillingRun::find($runId);
        if (!$run) {
            throw BillingDomainException::notFound('BILLING_RUN_NOT_FOUND', "Billing run #{$runId} not found.");
        }

        $query = BillingRunItem::with(['enrollment.student', 'enrollment.offering.programClass', 'invoice'])
            ->where('billing_run_id', $runId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $query->orderBy('id')->paginate($perPage);
    }

    /**
     * Pure read-only preview of a billing run. Zero database writes.
     */
    public function previewRun(array $data): array
    {
        [$period, $runType, $billingMonthDate, $offeringId] = $this->validateRunScope($data);

        $enrollments = $this->queryScopeEnrollments($period->id, $offeringId);

        $eligible = 0;
        $alreadyInvoiced = 0;
        $blocked = 0;
        $candidates = [];

        foreach ($enrollments as $enr) {
            $sourceKey = $this->buildSourceKey($runType, $enr->id, $billingMonthDate);
            $eval = $this->evaluateCandidate($enr, $period, $runType, $billingMonthDate, $sourceKey);

            if ($eval['outcome'] === 'ELIGIBLE') {
                $eligible++;
            } elseif ($eval['outcome'] === 'ALREADY_INVOICED') {
                $alreadyInvoiced++;
            } else {
                $blocked++;
            }

            $candidates[] = [
                'enrollment_id' => $enr->id,
                'student_record_id' => $enr->student_id,
                'student_public_id' => $enr->student?->student_id,
                'student_name_bn' => $enr->student?->name_bn,
                'student_name_en' => $enr->student?->name_en,
                'outcome' => $eval['outcome'],
                'reason_code' => $eval['reason_code'],
                'estimated_amount' => $eval['estimated_amount'],
                'source_key' => $sourceKey,
            ];
        }

        $previewHash = hash('sha256', json_encode([
            'type' => $runType->value,
            'period_id' => $period->id,
            'offering_id' => $offeringId,
            'billing_month' => $billingMonthDate,
            'eligible' => $eligible,
        ]));

        return [
            'type' => $runType->value,
            'academic_period_id' => $period->id,
            'academic_offering_id' => $offeringId,
            'billing_month' => $billingMonthDate ? substr($billingMonthDate, 0, 7) : null,
            'eligible' => $eligible,
            'already_invoiced' => $alreadyInvoiced,
            'blocked' => $blocked,
            'total_candidates' => count($candidates),
            'preview_token' => $previewHash,
            'candidates' => $candidates,
        ];
    }

    /**
     * Save billing run intent and populate run items.
     */
    public function createRun(array $data, int $actorId, ?string $idempotencyKey = null): array
    {
        [$period, $runType, $billingMonthDate, $offeringId] = $this->validateRunScope($data);

        $enrollments = $this->queryScopeEnrollments($period->id, $offeringId);

        return DB::transaction(function () use ($period, $runType, $billingMonthDate, $offeringId, $enrollments, $data, $actorId, $idempotencyKey) {
            $year = (int) Carbon::now('Asia/Dhaka')->format('Y');
            $runNumber = $this->generateNextRunNumber($year);

            $requestHash = hash('sha256', json_encode([
                'type' => $runType->value,
                'academic_period_id' => $period->id,
                'academic_offering_id' => $offeringId,
                'billing_month' => $billingMonthDate,
            ]));

            $run = BillingRun::create([
                'run_number' => $runNumber,
                'type' => $runType,
                'academic_period_id' => $period->id,
                'academic_offering_id' => $offeringId,
                'billing_month' => $billingMonthDate,
                'status' => BillingRunStatus::PREVIEWED,
                'requested_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'preview_hash' => $data['preview_token'] ?? $requestHash,
                'notes' => $data['notes'] ?? null,
                'lock_version' => 1,
            ]);

            foreach ($enrollments as $enr) {
                $sourceKey = $this->buildSourceKey($runType, $enr->id, $billingMonthDate);

                BillingRunItem::create([
                    'billing_run_id' => $run->id,
                    'enrollment_id' => $enr->id,
                    'source_key' => $sourceKey,
                    'status' => BillingRunItemStatus::PENDING,
                    'attempt_count' => 0,
                ]);
            }

            return $this->getRunDetail($run->id);
        });
    }

    /**
     * Execute a billing run idempotently with per-row failure isolation.
     */
    public function executeRun(int $id, int $actorId): array
    {
        $run = BillingRun::with('period.program')->find($id);
        if (!$run) {
            throw BillingDomainException::notFound('BILLING_RUN_NOT_FOUND', "Billing run #{$id} not found.");
        }

        if ($run->status === BillingRunStatus::COMPLETED) {
            return $this->getRunDetail($run->id);
        }

        if ($run->period && $run->period->state === PeriodState::CLOSED) {
            throw BillingDomainException::validation(
                'BILLING_PERIOD_CLOSED',
                'Cannot execute a billing run for a closed academic period.'
            );
        }

        $run->update([
            'status' => BillingRunStatus::RUNNING,
            'started_at' => $run->started_at ?? now(),
            'lock_version' => $run->lock_version + 1,
        ]);

        $pendingItems = BillingRunItem::where('billing_run_id', $run->id)
            ->where('status', BillingRunItemStatus::PENDING->value)
            ->orderBy('id')
            ->get();

        foreach ($pendingItems as $item) {
            $this->processSingleRunItem($run, $item, $actorId);
        }

        $this->finalizeRunStatus($run);

        return $this->getRunDetail($run->id);
    }

    /**
     * Retry ONLY failed rows in a billing run without touching succeeded/skipped rows (C38).
     */
    public function retryFailedRun(int $id, int $actorId): array
    {
        $run = BillingRun::with('period.program')->find($id);
        if (!$run) {
            throw BillingDomainException::notFound('BILLING_RUN_NOT_FOUND', "Billing run #{$id} not found.");
        }

        $failedItems = BillingRunItem::where('billing_run_id', $run->id)
            ->where('status', BillingRunItemStatus::FAILED->value)
            ->orderBy('id')
            ->get();

        if ($failedItems->isEmpty()) {
            return $this->getRunDetail($run->id);
        }

        $run->update([
            'status' => BillingRunStatus::RUNNING,
            'lock_version' => $run->lock_version + 1,
        ]);

        foreach ($failedItems as $item) {
            $this->processSingleRunItem($run, $item, $actorId);
        }

        $this->finalizeRunStatus($run);

        return $this->getRunDetail($run->id);
    }

    private function processSingleRunItem(BillingRun $run, BillingRunItem $item, int $actorId): void
    {
        try {
            DB::transaction(function () use ($run, $item, $actorId) {
                $lockedItem = BillingRunItem::lockForUpdate()->find($item->id);
                if (!$lockedItem || $lockedItem->status === BillingRunItemStatus::SUCCEEDED) {
                    return;
                }

                $lockedItem->status = BillingRunItemStatus::PROCESSING;
                $lockedItem->attempt_count += 1;
                $lockedItem->save();

                // 1. Check if invoice with source_key already exists (VAL-09, C31, C32)
                $existingInvoice = Invoice::where('source_key', $lockedItem->source_key)
                    ->where('status', '!=', 'VOID')
                    ->first();

                if ($existingInvoice) {
                    $lockedItem->status = BillingRunItemStatus::SKIPPED;
                    $lockedItem->invoice_id = $existingInvoice->id;
                    $lockedItem->reason_code = 'ALREADY_INVOICED';
                    $lockedItem->last_error = null;
                    $lockedItem->save();
                    return;
                }

                // 2. Load and validate enrollment at execution time (VAL-07)
                $enrollment = Enrollment::with(['student', 'period', 'offering.programClass'])->find($lockedItem->enrollment_id);
                if (!$enrollment) {
                    throw BillingDomainException::notFound('ENROLLMENT_NOT_FOUND', 'Enrollment not found.');
                }

                $this->invoiceService->assertEnrollmentEligibleForBilling($enrollment);

                // 3. Resolve effective fee structure
                $effectiveDate = $run->billing_month
                    ? $run->billing_month->format('Y-m-d')
                    : ($run->period?->start_date?->format('Y-m-d') ?? Carbon::now('Asia/Dhaka')->format('Y-m-d'));

                $structure = $this->feeStructureService->resolveEffectiveStructure(
                    (int) $run->period->program_id,
                    (int) $run->academic_period_id,
                    $enrollment->academic_offering_id ? (int) $enrollment->academic_offering_id : null,
                    $enrollment->offering?->program_class_id ? (int) $enrollment->offering->program_class_id : null,
                    $effectiveDate
                );

                // 4. Filter charge items for this run type
                $matchingItems = $this->filterStructureItemsForRunType($structure, $run->type);
                if ($matchingItems->isEmpty()) {
                    throw BillingDomainException::validation(
                        'NO_MATCHING_CHARGE_LINES',
                        "No charge lines found on structure #{$structure->id} for run type {$run->type->value}."
                    );
                }

                $billingMonthStr = $run->billing_month?->format('Y-m-d');
                $monthLabelEn = $run->billing_month ? $run->billing_month->format('M Y') : '';
                $dueDate = $run->billing_month
                    ? $run->billing_month->copy()->endOfMonth()->format('Y-m-d')
                    : Carbon::now('Asia/Dhaka')->addDays(14)->format('Y-m-d');

                $lines = [];
                foreach ($matchingItems as $idx => $sItem) {
                    $descBn = $sItem->feeCategory?->name_bn ?? 'মাসিক টিউশন ফি';
                    $descEn = $sItem->feeCategory?->name_en ?? 'Monthly Tuition Fee';
                    if ($monthLabelEn) {
                        $descEn .= " ({$monthLabelEn})";
                        $descBn .= " ({$monthLabelEn})";
                    }

                    $lines[] = [
                        'fee_category_id' => $sItem->fee_category_id,
                        'fee_structure_item_id' => $sItem->id,
                        'source_version' => $structure->version,
                        'description_bn' => $descBn,
                        'description_en' => $descEn,
                        'quantity' => 1,
                        'unit_amount' => number_format((float) $sItem->amount, 2, '.', ''),
                        'admission_required' => (bool) $sItem->admission_required,
                        'billing_month' => $billingMonthStr,
                        'sort_order' => $sItem->sort_order ?: ($idx + 1),
                    ];
                }

                $sourceType = match ($run->type) {
                    BillingRunType::ENROLLMENT => InvoiceSourceType::ENROLLMENT_ADMISSION->value,
                    BillingRunType::EXAM => InvoiceSourceType::EXAM->value,
                    default => InvoiceSourceType::MONTHLY_RUN->value,
                };

                $invoiceResult = $this->invoiceService->createDraftInvoice([
                    'enrollment_id' => $enrollment->id,
                    'fee_structure_id' => $structure->id,
                    'source_type' => $sourceType,
                    'source_key' => $lockedItem->source_key,
                    'due_at' => $dueDate,
                    'items' => $lines,
                    'auto_issue' => true,
                ], $actorId);

                $lockedItem->status = BillingRunItemStatus::SUCCEEDED;
                $lockedItem->invoice_id = $invoiceResult['id'];
                $lockedItem->reason_code = null;
                $lockedItem->last_error = null;
                $lockedItem->save();
            });
        } catch (\Throwable $e) {
            $reasonCode = $e instanceof BillingDomainException ? $e->errorCode : 'EXECUTION_FAILED';
            BillingRunItem::where('id', $item->id)->update([
                'status' => BillingRunItemStatus::FAILED->value,
                'reason_code' => $reasonCode,
                'last_error' => $e->getMessage(),
            ]);
        }
    }

    private function finalizeRunStatus(BillingRun $run): void
    {
        $failedCount = BillingRunItem::where('billing_run_id', $run->id)
            ->where('status', BillingRunItemStatus::FAILED->value)
            ->count();

        $succeededOrSkipped = BillingRunItem::where('billing_run_id', $run->id)
            ->whereIn('status', [BillingRunItemStatus::SUCCEEDED->value, BillingRunItemStatus::SKIPPED->value])
            ->count();

        if ($failedCount > 0 && $succeededOrSkipped === 0) {
            $finalStatus = BillingRunStatus::FAILED;
        } elseif ($failedCount > 0) {
            $finalStatus = BillingRunStatus::COMPLETED_WITH_ERRORS;
        } else {
            $finalStatus = BillingRunStatus::COMPLETED;
        }

        $run->update([
            'status' => $finalStatus,
            'completed_at' => now(),
            'lock_version' => $run->lock_version + 1,
        ]);
    }

    private function validateRunScope(array $data): array
    {
        $periodId = (int) ($data['academic_period_id'] ?? 0);
        $period = AcademicPeriod::with('program')->find($periodId);
        if (!$period) {
            throw BillingDomainException::notFound('ACADEMIC_PERIOD_NOT_FOUND', 'Selected academic period not found.');
        }

        if ($period->state === PeriodState::CLOSED || $period->state === PeriodState::ARCHIVED) {
            throw BillingDomainException::validation(
                'BILLING_PERIOD_CLOSED',
                'Cannot create or execute a billing run for a closed or archived academic period.'
            );
        }

        $runType = isset($data['type'])
            ? (BillingRunType::tryFrom($data['type']) ?? BillingRunType::MONTHLY_TUITION)
            : BillingRunType::MONTHLY_TUITION;

        $billingMonthDate = null;
        if ($runType === BillingRunType::MONTHLY_TUITION || !empty($data['billing_month'])) {
            $rawMonth = trim((string) ($data['billing_month'] ?? ''));
            if ($rawMonth === '') {
                throw BillingDomainException::validation('BILLING_MONTH_REQUIRED', 'billing_month (YYYY-MM) is required for monthly tuition runs.');
            }

            if (preg_match('/^\d{4}-\d{2}$/', $rawMonth)) {
                $monthStart = Carbon::createFromFormat('Y-m-d', "{$rawMonth}-01", 'Asia/Dhaka')->startOfMonth();
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawMonth)) {
                $monthStart = Carbon::createFromFormat('Y-m-d', $rawMonth, 'Asia/Dhaka')->startOfMonth();
            } else {
                throw BillingDomainException::validation('INVALID_BILLING_MONTH', 'billing_month must be in YYYY-MM or YYYY-MM-DD format.');
            }

            $monthEnd = $monthStart->copy()->endOfMonth();
            $periodStart = Carbon::parse($period->start_date->format('Y-m-d'), 'Asia/Dhaka')->startOfDay();
            $periodEnd = Carbon::parse($period->end_date->format('Y-m-d'), 'Asia/Dhaka')->endOfDay();

            if ($monthEnd->lt($periodStart) || $monthStart->gt($periodEnd)) {
                throw BillingDomainException::validation(
                    'BILLING_MONTH_OUTSIDE_PERIOD',
                    "Billing month {$monthStart->format('Y-m')} does not fall within academic period dates [{$periodStart->format('Y-m-d')} to {$periodEnd->format('Y-m-d')}]."
                );
            }

            $billingMonthDate = $monthStart->format('Y-m-d');
        }

        $offeringId = !empty($data['academic_offering_id']) ? (int) $data['academic_offering_id'] : null;

        return [$period, $runType, $billingMonthDate, $offeringId];
    }

    private function queryScopeEnrollments(int $periodId, ?int $offeringId)
    {
        $query = Enrollment::with(['student', 'offering.programClass'])
            ->where('academic_period_id', $periodId);

        if ($offeringId) {
            $query->where('academic_offering_id', $offeringId);
        }

        return $query->orderBy('id')->get();
    }

    private function evaluateCandidate(
        Enrollment $enr,
        AcademicPeriod $period,
        BillingRunType $runType,
        ?string $billingMonthDate,
        string $sourceKey
    ): array {
        $status = $enr->status instanceof EnrollmentStatus
            ? $enr->status
            : EnrollmentStatus::tryFrom((string) $enr->status);

        if (in_array($status, [EnrollmentStatus::CANCELLED, EnrollmentStatus::REJECTED], true)) {
            return [
                'outcome' => 'BLOCKED',
                'reason_code' => 'ENROLLMENT_INELIGIBLE',
                'estimated_amount' => '0.00',
            ];
        }

        if (Invoice::where('source_key', $sourceKey)->where('status', '!=', 'VOID')->exists()) {
            return [
                'outcome' => 'ALREADY_INVOICED',
                'reason_code' => 'ALREADY_INVOICED',
                'estimated_amount' => '0.00',
            ];
        }

        try {
            $effectiveDate = $billingMonthDate ?? $period->start_date->format('Y-m-d');
            $structure = $this->feeStructureService->resolveEffectiveStructure(
                (int) $period->program_id,
                (int) $period->id,
                $enr->academic_offering_id ? (int) $enr->academic_offering_id : null,
                $enr->offering?->program_class_id ? (int) $enr->offering->program_class_id : null,
                $effectiveDate
            );

            $items = $this->filterStructureItemsForRunType($structure, $runType);
            if ($items->isEmpty()) {
                return [
                    'outcome' => 'BLOCKED',
                    'reason_code' => 'NO_MATCHING_CHARGE_LINES',
                    'estimated_amount' => '0.00',
                ];
            }

            $sum = '0.00';
            foreach ($items as $it) {
                $sum = bcadd($sum, number_format((float) $it->amount, 2, '.', ''), 2);
            }

            return [
                'outcome' => 'ELIGIBLE',
                'reason_code' => null,
                'estimated_amount' => $sum,
            ];
        } catch (\Throwable) {
            return [
                'outcome' => 'BLOCKED',
                'reason_code' => 'FEE_STRUCTURE_NOT_FOUND',
                'estimated_amount' => '0.00',
            ];
        }
    }

    private function filterStructureItemsForRunType($structure, BillingRunType $runType)
    {
        return $structure->items->filter(function ($item) use ($runType) {
            return match ($runType) {
                BillingRunType::MONTHLY_TUITION => $item->cadence === FeeCadence::MONTHLY,
                BillingRunType::ENROLLMENT => $item->admission_required || in_array($item->cadence, [FeeCadence::PER_ENROLLMENT, FeeCadence::ANNUAL], true),
                BillingRunType::EXAM => $item->cadence === FeeCadence::TERMLY || $item->feeCategory?->billing_type?->value === 'EXAM',
                default => true,
            };
        });
    }

    public function buildSourceKey(BillingRunType $runType, int $enrollmentId, ?string $billingMonthDate): string
    {
        if ($runType === BillingRunType::MONTHLY_TUITION && $billingMonthDate) {
            $ym = substr($billingMonthDate, 0, 7);
            return "MONTHLY_TUITION:ENR:{$enrollmentId}:{$ym}";
        }

        if ($runType === BillingRunType::ENROLLMENT) {
            return "ENROLLMENT_ADMISSION:ENR:{$enrollmentId}";
        }

        $ctx = $billingMonthDate ? substr($billingMonthDate, 0, 7) : 'GENERAL';
        return "{$runType->value}:ENR:{$enrollmentId}:{$ctx}";
    }

    private function generateNextRunNumber(int $year): string
    {
        $prefix = "RUN-{$year}-";
        $latest = BillingRun::where('run_number', 'like', "{$prefix}%")
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->value('run_number');

        $nextSeq = 1;
        if ($latest && preg_match('/^RUN-\d{4}-(\d+)$/', $latest, $m)) {
            $nextSeq = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
