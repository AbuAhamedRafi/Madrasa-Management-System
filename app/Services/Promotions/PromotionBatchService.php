<?php

namespace App\Services\Promotions;

use App\Enums\EnrollmentStatus;
use App\Enums\PromotionAction;
use App\Enums\PromotionBatchMode;
use App\Enums\PromotionBatchStatus;
use App\Enums\PromotionItemStatus;
use App\Exceptions\PromotionDomainException;
use App\Models\AcademicOffering;
use App\Models\Enrollment;
use App\Models\OfferingSection;
use App\Models\PromotionBatch;
use App\Models\PromotionEvent;
use App\Models\PromotionItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionBatchService
{
    public function __construct(
        protected PromotionEligibilityService $eligibilityService
    ) {}

    /**
     * List batches with filters and counters.
     */
    public function listBatches(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PromotionBatch::with([
            'sourceAcademicPeriod',
            'sourceOfferingSection.offering.programClass',
            'targetAcademicPeriod',
            'targetAcademicOffering.programClass.program',
            'creator',
            'submitter',
        ])
        ->withCount([
            'items as total_items',
            'items as eligible_items' => fn($q) => $q->where('status', PromotionItemStatus::ELIGIBLE->value),
            'items as succeeded_items' => fn($q) => $q->where('status', PromotionItemStatus::SUCCEEDED->value),
            'items as failed_items' => fn($q) => $q->where('status', PromotionItemStatus::FAILED->value),
            'items as pending_items' => fn($q) => $q->whereIn('status', [PromotionItemStatus::PENDING->value, PromotionItemStatus::RETRY_QUEUED->value]),
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['source_academic_period_id'])) {
            $query->where('source_academic_period_id', $filters['source_academic_period_id']);
        }
        if (!empty($filters['target_academic_period_id'])) {
            $query->where('target_academic_period_id', $filters['target_academic_period_id']);
        }
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where('batch_number', 'ILIKE', "%{$term}%");
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * Get single batch details with counts.
     */
    public function getBatch(int $batchId): PromotionBatch
    {
        return PromotionBatch::with([
            'sourceAcademicPeriod.program',
            'sourceOfferingSection.offering.programClass',
            'targetAcademicPeriod.program',
            'targetAcademicOffering.programClass.program',
            'targetAcademicOffering.sections',
            'creator',
            'submitter',
        ])
        ->withCount([
            'items as total_items',
            'items as eligible_items' => fn($q) => $q->where('status', PromotionItemStatus::ELIGIBLE->value),
            'items as succeeded_items' => fn($q) => $q->where('status', PromotionItemStatus::SUCCEEDED->value),
            'items as failed_items' => fn($q) => $q->where('status', PromotionItemStatus::FAILED->value),
            'items as pending_items' => fn($q) => $q->whereIn('status', [PromotionItemStatus::PENDING->value, PromotionItemStatus::RETRY_QUEUED->value]),
        ])
        ->findOrFail($batchId);
    }

    /**
     * Create a new bulk promotion batch draft.
     */
    public function createBatch(array $data, int $actorId): PromotionBatch
    {
        return DB::transaction(function () use ($data, $actorId) {
            $year = date('Y');
            $latestBatch = PromotionBatch::where('batch_number', 'LIKE', "PRM-{$year}-%")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $nextSeq = 1;
            if ($latestBatch && preg_match('/PRM-\d{4}-(\d+)/', $latestBatch->batch_number, $matches)) {
                $nextSeq = ((int) $matches[1]) + 1;
            }
            $batchNumber = sprintf('PRM-%s-%04d', $year, $nextSeq);

            $action = isset($data['action']) ? PromotionAction::from($data['action']) : PromotionAction::PROMOTE;
            $mode = isset($data['mode']) ? PromotionBatchMode::from($data['mode']) : PromotionBatchMode::BULK;

            $batch = PromotionBatch::create([
                'batch_number' => $batchNumber,
                'mode' => $mode,
                'action' => $action,
                'source_academic_period_id' => $data['source_academic_period_id'],
                'source_offering_section_id' => $data['source_offering_section_id'] ?? null,
                'target_academic_period_id' => $data['target_academic_period_id'],
                'target_academic_offering_id' => $data['target_academic_offering_id'],
                'status' => PromotionBatchStatus::DRAFT,
                'lock_version' => 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            // Determine target section
            $targetOffering = AcademicOffering::with('sections')->findOrFail($data['target_academic_offering_id']);
            $defaultSection = null;
            if (!empty($data['default_target_section_id'])) {
                $defaultSection = OfferingSection::findOrFail($data['default_target_section_id']);
            } elseif ($targetOffering->sections->isNotEmpty()) {
                $defaultSection = $targetOffering->sections->first();
            }

            if (!$defaultSection) {
                throw PromotionDomainException::ineligible(
                    'NO_TARGET_SECTION',
                    'Target academic offering has no active sections.'
                );
            }

            // Populate items: either specific student IDs or all approved students in source scope
            $enrollmentQuery = Enrollment::where('academic_period_id', $data['source_academic_period_id'])
                ->where('status', EnrollmentStatus::APPROVED->value);

            if (!empty($data['source_offering_section_id'])) {
                $enrollmentQuery->where('offering_section_id', $data['source_offering_section_id']);
            }

            if (!empty($data['student_ids']) && is_array($data['student_ids'])) {
                $enrollmentQuery->whereIn('student_id', $data['student_ids']);
            }

            $sourceEnrollments = $enrollmentQuery->get();

            foreach ($sourceEnrollments as $enrollment) {
                PromotionItem::create([
                    'batch_id' => $batch->id,
                    'student_id' => $enrollment->student_id,
                    'source_enrollment_id' => $enrollment->id,
                    'target_offering_section_id' => $defaultSection->id,
                    'action' => $action,
                    'academic_eligibility_confirmed' => false,
                    'status' => PromotionItemStatus::PENDING,
                    'attempt_count' => 0,
                ]);
            }

            PromotionEvent::create([
                'promotion_batch_id' => $batch->id,
                'actor_user_id' => $actorId,
                'action' => 'BATCH_CREATED',
                'after_json' => [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'items_count' => $sourceEnrollments->count(),
                ],
                'occurred_at' => now(),
            ]);

            return $this->getBatch($batch->id);
        });
    }

    /**
     * Update draft batch.
     */
    public function updateBatch(int $batchId, array $data, int $actorId): PromotionBatch
    {
        return DB::transaction(function () use ($batchId, $data, $actorId) {
            $batch = PromotionBatch::lockForUpdate()->findOrFail($batchId);

            if ($batch->status !== PromotionBatchStatus::DRAFT && $batch->status !== PromotionBatchStatus::PREVIEWED) {
                throw PromotionDomainException::conflict(
                    'INVALID_BATCH_STATE',
                    "Only batches in DRAFT or PREVIEWED status can be updated. Current status is {$batch->status->value}."
                );
            }

            $expectedVersion = (int) $data['lock_version'];
            if ($batch->lock_version !== $expectedVersion) {
                throw PromotionDomainException::staleVersion($batch->lock_version, $expectedVersion);
            }

            $before = $batch->toArray();

            if (isset($data['notes'])) {
                $batch->notes = $data['notes'];
            }
            if (isset($data['action'])) {
                $batch->action = PromotionAction::from($data['action']);
            }

            $batch->lock_version += 1;
            $batch->status = PromotionBatchStatus::DRAFT;
            $batch->save();

            PromotionEvent::create([
                'promotion_batch_id' => $batch->id,
                'actor_user_id' => $actorId,
                'action' => 'BATCH_UPDATED',
                'before_json' => $before,
                'after_json' => $batch->toArray(),
                'occurred_at' => now(),
            ]);

            return $this->getBatch($batch->id);
        });
    }

    /**
     * Cancel draft or previewed batch.
     */
    public function cancelBatch(int $batchId, int $actorId): PromotionBatch
    {
        return DB::transaction(function () use ($batchId, $actorId) {
            $batch = PromotionBatch::lockForUpdate()->findOrFail($batchId);

            if ($batch->status !== PromotionBatchStatus::DRAFT && $batch->status !== PromotionBatchStatus::PREVIEWED) {
                throw PromotionDomainException::conflict(
                    'INVALID_BATCH_STATE',
                    "Only batches in DRAFT or PREVIEWED status can be cancelled."
                );
            }

            $batch->status = PromotionBatchStatus::CANCELLED;
            $batch->lock_version += 1;
            $batch->save();

            PromotionEvent::create([
                'promotion_batch_id' => $batch->id,
                'actor_user_id' => $actorId,
                'action' => 'BATCH_CANCELLED',
                'occurred_at' => now(),
            ]);

            return $this->getBatch($batch->id);
        });
    }

    /**
     * List items in a batch.
     */
    public function listItems(int $batchId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = PromotionItem::with([
            'student',
            'sourceEnrollment.period',
            'sourceEnrollment.offering.programClass',
            'sourceEnrollment.section',
            'targetOfferingSection.offering.programClass',
            'targetEnrollment',
        ])
        ->where('batch_id', $batchId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->whereHas('student', function ($sq) use ($term) {
                $sq->where('student_id', 'ILIKE', "%{$term}%")
                   ->orWhere('full_name_en', 'ILIKE', "%{$term}%")
                   ->orWhere('full_name_bn', 'ILIKE', "%{$term}%");
            });
        }

        return $query->orderBy('id', 'asc')->paginate($perPage);
    }

    /**
     * Update an individual item in a draft batch.
     */
    public function updateItem(int $batchId, int $itemId, array $data, int $actorId): PromotionItem
    {
        return DB::transaction(function () use ($batchId, $itemId, $data, $actorId) {
            $batch = PromotionBatch::findOrFail($batchId);
            if ($batch->status !== PromotionBatchStatus::DRAFT && $batch->status !== PromotionBatchStatus::PREVIEWED) {
                throw PromotionDomainException::conflict('INVALID_BATCH_STATE', 'Cannot modify items on a non-draft batch.');
            }

            $item = PromotionItem::where('batch_id', $batchId)->findOrFail($itemId);

            if (isset($data['target_offering_section_id'])) {
                $item->target_offering_section_id = $data['target_offering_section_id'];
            }
            if (isset($data['action'])) {
                $item->action = PromotionAction::from($data['action']);
            }
            if (isset($data['academic_eligibility_confirmed'])) {
                $item->academic_eligibility_confirmed = (bool) $data['academic_eligibility_confirmed'];
                $item->eligibility_confirmed_by = $actorId;
                $item->eligibility_confirmed_at = now();
            }

            $item->status = PromotionItemStatus::PENDING;
            $item->error_code = null;
            $item->safe_error_message = null;
            $item->save();

            $batch->status = PromotionBatchStatus::DRAFT;
            $batch->lock_version += 1;
            $batch->save();

            return $item->load(['student', 'sourceEnrollment', 'targetOfferingSection']);
        });
    }

    /**
     * Remove an item from a draft batch.
     */
    public function removeItem(int $batchId, int $itemId): void
    {
        $batch = PromotionBatch::findOrFail($batchId);
        if ($batch->status !== PromotionBatchStatus::DRAFT && $batch->status !== PromotionBatchStatus::PREVIEWED) {
            throw PromotionDomainException::conflict('INVALID_BATCH_STATE', 'Cannot remove items from a non-draft batch.');
        }

        $item = PromotionItem::where('batch_id', $batchId)->findOrFail($itemId);
        $item->delete();

        $batch->status = PromotionBatchStatus::DRAFT;
        $batch->lock_version += 1;
        $batch->save();
    }

    /**
     * Preview entire bulk batch (read-only evaluation of all items).
     */
    public function previewBatch(int $batchId, int $actorId): array
    {
        return DB::transaction(function () use ($batchId, $actorId) {
            $batch = PromotionBatch::lockForUpdate()->findOrFail($batchId);

            if ($batch->status !== PromotionBatchStatus::DRAFT && $batch->status !== PromotionBatchStatus::PREVIEWED) {
                throw PromotionDomainException::conflict('INVALID_BATCH_STATE', 'Only DRAFT or PREVIEWED batches can be previewed.');
            }

            $items = PromotionItem::where('batch_id', $batchId)->get();

            $eligibleCount = 0;
            $ineligibleCount = 0;

            foreach ($items as $item) {
                $eval = $this->eligibilityService->evaluate(
                    $item->student_id,
                    $item->source_enrollment_id,
                    $item->target_offering_section_id,
                    $batch->target_academic_period_id,
                    $item->action,
                    $item->academic_eligibility_confirmed,
                    false
                );

                if ($eval['is_eligible']) {
                    $item->status = PromotionItemStatus::ELIGIBLE;
                    $item->error_code = null;
                    $item->safe_error_message = null;
                    $eligibleCount++;
                } else {
                    $firstError = $eval['errors'][0];
                    $item->status = PromotionItemStatus::FAILED;
                    $item->error_code = $firstError['code'];
                    $item->safe_error_message = $firstError['message'];
                    $ineligibleCount++;
                }
                $item->save();
            }

            $batch->status = PromotionBatchStatus::PREVIEWED;
            $batch->lock_version += 1;
            $batch->save();

            PromotionEvent::create([
                'promotion_batch_id' => $batch->id,
                'actor_user_id' => $actorId,
                'action' => 'BATCH_PREVIEWED',
                'after_json' => [
                    'total' => $items->count(),
                    'eligible' => $eligibleCount,
                    'ineligible' => $ineligibleCount,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'batch_id' => $batch->id,
                'status' => $batch->status->value,
                'total_items' => $items->count(),
                'eligible_count' => $eligibleCount,
                'ineligible_count' => $ineligibleCount,
            ];
        });
    }
}

