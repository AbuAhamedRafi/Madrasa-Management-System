<?php

namespace App\Services\Promotions;

use App\Enums\EnrollmentType;
use App\Enums\PromotionBatchStatus;
use App\Enums\PromotionItemStatus;
use App\Exceptions\PromotionDomainException;
use App\Models\Enrollment;
use App\Models\OfferingSection;
use App\Models\PromotionAttempt;
use App\Models\PromotionBatch;
use App\Models\PromotionEvent;
use App\Models\PromotionIdempotencyKey;
use App\Models\PromotionItem;
use App\Services\Admissions\EnrollmentPlacementService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionExecutionService
{
    public function __construct(
        protected PromotionEligibilityService $eligibilityService,
        protected EnrollmentPlacementService $placementService
    ) {}

    /**
     * Execute a bulk promotion batch.
     */
    public function executeBatch(int $batchId, int $actorId, ?string $idempotencyKey = null): array
    {
        $batch = PromotionBatch::findOrFail($batchId);

        if (!$batch->status->canBeExecuted()) {
            throw PromotionDomainException::conflict(
                'INVALID_BATCH_STATE',
                "Batch #{$batch->batch_number} is in status {$batch->status->value} and cannot be executed."
            );
        }

        // Idempotency check if key provided
        if ($idempotencyKey) {
            $reqHash = hash('sha256', "batch:{$batchId}");
            $existingKey = PromotionIdempotencyKey::where('scope', 'BATCH_EXECUTION')
                ->where('key', $idempotencyKey)
                ->first();

            if ($existingKey) {
                if ($existingKey->request_hash !== $reqHash) {
                    throw PromotionDomainException::idempotentMismatch($idempotencyKey);
                }
                if ($existingKey->state === 'COMPLETED' && $existingKey->response_body) {
                    return $existingKey->response_body;
                }
            } else {
                PromotionIdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'scope' => 'BATCH_EXECUTION',
                    'request_hash' => $reqHash,
                    'state' => 'IN_PROGRESS',
                    'created_at' => now(),
                    'expires_at' => now()->addDay(),
                ]);
            }
        }

        $batch->update([
            'status' => PromotionBatchStatus::RUNNING,
            'submitted_by' => $actorId,
            'started_at' => $batch->started_at ?? now(),
        ]);

        PromotionEvent::create([
            'promotion_batch_id' => $batch->id,
            'actor_user_id' => $actorId,
            'action' => 'BATCH_EXECUTION_STARTED',
            'occurred_at' => now(),
        ]);

        // Process items
        $this->processBatchItems($batch, $actorId);

        $batch->refresh();

        $result = [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'status' => $batch->status->value,
            'started_at' => $batch->started_at?->toISOString(),
            'finished_at' => $batch->finished_at?->toISOString(),
            'items_count' => [
                'total' => $batch->items()->count(),
                'succeeded' => $batch->items()->where('status', PromotionItemStatus::SUCCEEDED->value)->count(),
                'failed' => $batch->items()->where('status', PromotionItemStatus::FAILED->value)->count(),
                'pending' => $batch->items()->whereIn('status', [PromotionItemStatus::PENDING->value, PromotionItemStatus::RETRY_QUEUED->value])->count(),
            ],
        ];

        if ($idempotencyKey) {
            PromotionIdempotencyKey::where('scope', 'BATCH_EXECUTION')
                ->where('key', $idempotencyKey)
                ->update([
                    'state' => 'COMPLETED',
                    'outcome_ref' => (string) $batch->id,
                    'response_code' => 200,
                    'response_body' => $result,
                ]);
        }

        return $result;
    }

    /**
     * Process items inside batch one by one in isolated transactions.
     */
    protected function processBatchItems(PromotionBatch $batch, int $actorId): void
    {
        $items = PromotionItem::where('batch_id', $batch->id)
            ->whereNotIn('status', [PromotionItemStatus::SUCCEEDED->value, PromotionItemStatus::SKIPPED->value])
            ->orderBy('id', 'asc')
            ->get();

        foreach ($items as $item) {
            // Check if operator requested pause
            $currentStatus = PromotionBatch::where('id', $batch->id)->value('status');
            if (in_array($currentStatus, [PromotionBatchStatus::PAUSING->value, PromotionBatchStatus::PAUSED->value], true)) {
                $batch->update(['status' => PromotionBatchStatus::PAUSED]);
                PromotionEvent::create([
                    'promotion_batch_id' => $batch->id,
                    'actor_user_id' => $actorId,
                    'action' => 'BATCH_PAUSED',
                    'occurred_at' => now(),
                ]);
                return;
            }

            $this->processSingleItem($batch, $item, $actorId);
        }

        // Evaluate final status
        $failedCount = PromotionItem::where('batch_id', $batch->id)
            ->where('status', PromotionItemStatus::FAILED->value)
            ->count();

        $finalStatus = $failedCount > 0 ? PromotionBatchStatus::COMPLETED_WITH_ERRORS : PromotionBatchStatus::COMPLETED;

        $batch->update([
            'status' => $finalStatus,
            'finished_at' => now(),
        ]);

        PromotionEvent::create([
            'promotion_batch_id' => $batch->id,
            'actor_user_id' => $actorId,
            'action' => 'BATCH_EXECUTION_FINISHED',
            'after_json' => [
                'status' => $finalStatus->value,
                'failed_items' => $failedCount,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Process a single promotion item with partial failure isolation.
     */
    protected function processSingleItem(PromotionBatch $batch, PromotionItem $item, int $actorId): void
    {
        $attemptNum = $item->attempt_count + 1;
        $attemptKey = "ATT-{$item->id}-{$attemptNum}-" . Str::random(8);

        $attempt = PromotionAttempt::create([
            'promotion_item_id' => $item->id,
            'attempt_number' => $attemptNum,
            'idempotency_key' => $attemptKey,
            'request_hash' => hash('sha256', "item:{$item->id}:att:{$attemptNum}"),
            'status' => 'STARTED',
            'started_at' => now(),
        ]);

        $item->update([
            'status' => PromotionItemStatus::PROCESSING,
            'attempt_count' => $attemptNum,
        ]);

        try {
            DB::transaction(function () use ($batch, $item, $actorId, $attempt) {
                // Strict eligibility check for execution
                $eval = $this->eligibilityService->evaluate(
                    $item->student_id,
                    $item->source_enrollment_id,
                    $item->target_offering_section_id,
                    $batch->target_academic_period_id,
                    $item->action,
                    $item->academic_eligibility_confirmed,
                    true
                );

                if (!$eval['is_eligible']) {
                    $firstError = $eval['errors'][0];
                    throw PromotionDomainException::ineligible(
                        $firstError['code'],
                        $firstError['message'],
                        ['errors' => $eval['errors']]
                    );
                }

                $sourceEnrollment = Enrollment::with(['programAdmission', 'offering.programClass', 'period'])->findOrFail($item->source_enrollment_id);
                $targetSection = OfferingSection::with(['offering.programClass'])->findOrFail($item->target_offering_section_id);
                $programId = $targetSection->offering->programClass->program_id;
                $cohortId = $sourceEnrollment->period?->cohort_id ?? $sourceEnrollment->programAdmission?->academic_cohort_id;

                // Create enrollment in D04 starting strictly in PENDING_BILLING
                $newEnrollment = $this->placementService->createEnrollment([
                    'student_record_id' => $item->student_id,
                    'academic_program_id' => $programId,
                    'academic_period_id' => $batch->target_academic_period_id,
                    'offering_section_id' => $targetSection->id,
                    'academic_cohort_id' => $cohortId,
                    'entry_type' => 'REGULAR',
                    'enrollment_type' => EnrollmentType::CONTINUING->value,
                    'notes' => "Batch {$batch->batch_number} item #{$item->id}",
                ], $actorId);

                $item->update([
                    'status' => PromotionItemStatus::SUCCEEDED,
                    'target_enrollment_id' => $newEnrollment->id,
                    'error_code' => null,
                    'safe_error_message' => null,
                ]);

                $attempt->update([
                    'status' => 'SUCCEEDED',
                    'finished_at' => now(),
                    'result_json' => [
                        'target_enrollment_id' => $newEnrollment->id,
                    ],
                ]);
            });
        } catch (Exception $e) {
            $errorCode = ($e instanceof PromotionDomainException) ? $e->errorCode : 'EXECUTION_ERROR';
            $errorMessage = $e->getMessage();

            $item->update([
                'status' => PromotionItemStatus::FAILED,
                'error_code' => $errorCode,
                'safe_error_message' => $errorMessage,
            ]);

            $attempt->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'error_code' => $errorCode,
                'result_json' => ['error' => $errorMessage],
            ]);
        }
    }

    /**
     * Pause a running or queued batch.
     */
    public function pauseBatch(int $batchId, int $actorId): PromotionBatch
    {
        return DB::transaction(function () use ($batchId, $actorId) {
            $batch = PromotionBatch::lockForUpdate()->findOrFail($batchId);

            if (!$batch->status->canBePaused()) {
                throw PromotionDomainException::conflict(
                    'INVALID_BATCH_STATE',
                    "Only RUNNING or QUEUED batches can be paused. Current status is {$batch->status->value}."
                );
            }

            $batch->status = PromotionBatchStatus::PAUSED;
            $batch->lock_version += 1;
            $batch->save();

            PromotionEvent::create([
                'promotion_batch_id' => $batch->id,
                'actor_user_id' => $actorId,
                'action' => 'BATCH_PAUSE_REQUESTED',
                'occurred_at' => now(),
            ]);

            return $batch;
        });
    }

    /**
     * Resume a paused batch.
     */
    public function resumeBatch(int $batchId, int $actorId): array
    {
        $batch = PromotionBatch::findOrFail($batchId);

        if (!$batch->status->canBeResumed()) {
            throw PromotionDomainException::conflict(
                'INVALID_BATCH_STATE',
                "Only PAUSED batches can be resumed. Current status is {$batch->status->value}."
            );
        }

        $batch->update([
            'status' => PromotionBatchStatus::RUNNING,
            'lock_version' => $batch->lock_version + 1,
        ]);

        PromotionEvent::create([
            'promotion_batch_id' => $batch->id,
            'actor_user_id' => $actorId,
            'action' => 'BATCH_RESUMED',
            'occurred_at' => now(),
        ]);

        $this->processBatchItems($batch, $actorId);

        $batch->refresh();

        return [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'status' => $batch->status->value,
            'items_count' => [
                'total' => $batch->items()->count(),
                'succeeded' => $batch->items()->where('status', PromotionItemStatus::SUCCEEDED->value)->count(),
                'failed' => $batch->items()->where('status', PromotionItemStatus::FAILED->value)->count(),
                'pending' => $batch->items()->whereIn('status', [PromotionItemStatus::PENDING->value, PromotionItemStatus::RETRY_QUEUED->value])->count(),
            ],
        ];
    }

    /**
     * Retry failed items in a batch that completed with errors.
     */
    public function retryFailedItems(int $batchId, int $actorId): array
    {
        $batch = PromotionBatch::findOrFail($batchId);

        if (!$batch->status->canBeRetried()) {
            throw PromotionDomainException::conflict(
                'INVALID_BATCH_STATE',
                "Only batches in COMPLETED_WITH_ERRORS status can be retried. Current status is {$batch->status->value}."
            );
        }

        // Reset failed items
        PromotionItem::where('batch_id', $batch->id)
            ->where('status', PromotionItemStatus::FAILED->value)
            ->update([
                'status' => PromotionItemStatus::RETRY_QUEUED,
                'error_code' => null,
                'safe_error_message' => null,
            ]);

        $batch->update([
            'status' => PromotionBatchStatus::RUNNING,
            'lock_version' => $batch->lock_version + 1,
        ]);

        PromotionEvent::create([
            'promotion_batch_id' => $batch->id,
            'actor_user_id' => $actorId,
            'action' => 'BATCH_RETRY_STARTED',
            'occurred_at' => now(),
        ]);

        $this->processBatchItems($batch, $actorId);

        $batch->refresh();

        return [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'status' => $batch->status->value,
            'items_count' => [
                'total' => $batch->items()->count(),
                'succeeded' => $batch->items()->where('status', PromotionItemStatus::SUCCEEDED->value)->count(),
                'failed' => $batch->items()->where('status', PromotionItemStatus::FAILED->value)->count(),
                'pending' => $batch->items()->whereIn('status', [PromotionItemStatus::PENDING->value, PromotionItemStatus::RETRY_QUEUED->value])->count(),
            ],
        ];
    }
}

