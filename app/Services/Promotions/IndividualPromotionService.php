<?php

namespace App\Services\Promotions;

use App\Enums\EnrollmentType;
use App\Enums\PromotionAction;
use App\Enums\PromotionBatchMode;
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

class IndividualPromotionService
{
    public function __construct(
        protected PromotionEligibilityService $eligibilityService,
        protected EnrollmentPlacementService $placementService
    ) {}

    /**
     * Preview an individual promotion or repetition.
     * Strictly read-only, produces zero side-effects and holds no seats.
     */
    public function preview(array $data): array
    {
        $studentId = (int) $data['student_id'];
        $sourceEnrollmentId = (int) $data['source_enrollment_id'];
        $targetOfferingSectionId = (int) $data['target_offering_section_id'];
        $targetAcademicPeriodId = (int) $data['target_academic_period_id'];
        $action = isset($data['action']) ? PromotionAction::from($data['action']) : PromotionAction::PROMOTE;
        $academicEligibilityConfirmed = (bool) ($data['academic_eligibility_confirmed'] ?? false);

        return $this->eligibilityService->evaluate(
            $studentId,
            $sourceEnrollmentId,
            $targetOfferingSectionId,
            $targetAcademicPeriodId,
            $action,
            $academicEligibilityConfirmed,
            false
        );
    }

    /**
     * Execute an individual promotion or repetition transactionally.
     *
     * @throws PromotionDomainException
     */
    public function promote(array $data, int $actorId, ?string $idempotencyKey = null): array
    {
        $studentId = (int) $data['student_id'];
        $sourceEnrollmentId = (int) $data['source_enrollment_id'];
        $targetOfferingSectionId = (int) $data['target_offering_section_id'];
        $targetAcademicPeriodId = (int) $data['target_academic_period_id'];
        $action = isset($data['action']) ? PromotionAction::from($data['action']) : PromotionAction::PROMOTE;
        $academicEligibilityConfirmed = (bool) ($data['academic_eligibility_confirmed'] ?? false);

        // Check idempotency if key provided
        if ($idempotencyKey) {
            $reqHash = hash('sha256', json_encode($data));
            $existingKey = PromotionIdempotencyKey::where('scope', 'INDIVIDUAL_PROMOTION')
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
                    'scope' => 'INDIVIDUAL_PROMOTION',
                    'request_hash' => $reqHash,
                    'state' => 'IN_PROGRESS',
                    'created_at' => now(),
                    'expires_at' => now()->addDay(),
                ]);
            }
        }

        // 1. Strict Eligibility Evaluation (isExecution = true)
        $evaluation = $this->eligibilityService->evaluate(
            $studentId,
            $sourceEnrollmentId,
            $targetOfferingSectionId,
            $targetAcademicPeriodId,
            $action,
            $academicEligibilityConfirmed,
            true
        );

        if (!$evaluation['is_eligible']) {
            $firstError = $evaluation['errors'][0];
            throw PromotionDomainException::ineligible(
                $firstError['code'],
                $firstError['message'],
                ['errors' => $evaluation['errors']]
            );
        }

        $sourceEnrollment = Enrollment::with(['programAdmission', 'offering.programClass', 'period'])->findOrFail($sourceEnrollmentId);
        $targetSection = OfferingSection::with(['offering.programClass'])->findOrFail($targetOfferingSectionId);
        $programId = $targetSection->offering->programClass->program_id;
        $cohortId = $sourceEnrollment->period?->cohort_id ?? $sourceEnrollment->programAdmission?->academic_cohort_id;

        return DB::transaction(function () use (
            $data,
            $studentId,
            $sourceEnrollment,
            $targetSection,
            $targetAcademicPeriodId,
            $action,
            $actorId,
            $idempotencyKey,
            $programId,
            $cohortId
        ) {
            // Generate unique batch number for individual promotion
            $batchNumber = 'PRM-IND-' . strtoupper(Str::random(10));

            $batch = PromotionBatch::create([
                'batch_number' => $batchNumber,
                'mode' => PromotionBatchMode::INDIVIDUAL,
                'action' => $action,
                'source_academic_period_id' => $sourceEnrollment->academic_period_id,
                'source_offering_section_id' => $sourceEnrollment->offering_section_id,
                'target_academic_period_id' => $targetAcademicPeriodId,
                'target_academic_offering_id' => $targetSection->academic_offering_id,
                'status' => PromotionBatchStatus::RUNNING,
                'lock_version' => 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'submitted_by' => $actorId,
                'started_at' => now(),
            ]);

            $promotionItem = PromotionItem::create([
                'batch_id' => $batch->id,
                'student_id' => $studentId,
                'source_enrollment_id' => $sourceEnrollment->id,
                'target_offering_section_id' => $targetSection->id,
                'action' => $action,
                'academic_eligibility_confirmed' => true,
                'eligibility_confirmed_by' => $actorId,
                'eligibility_confirmed_at' => now(),
                'status' => PromotionItemStatus::PROCESSING,
                'attempt_count' => 1,
            ]);

            $attemptKey = 'ATT-IND-' . Str::uuid()->toString();
            $attempt = PromotionAttempt::create([
                'promotion_item_id' => $promotionItem->id,
                'attempt_number' => 1,
                'idempotency_key' => $attemptKey,
                'request_hash' => hash('sha256', json_encode($data)),
                'status' => 'STARTED',
                'started_at' => now(),
            ]);

            try {
                // Call D04 placement service to create target enrollment in PENDING_BILLING
                $newEnrollment = $this->placementService->createEnrollment([
                    'student_record_id' => $studentId,
                    'academic_program_id' => $programId,
                    'academic_period_id' => $targetAcademicPeriodId,
                    'offering_section_id' => $targetSection->id,
                    'academic_cohort_id' => $cohortId,
                    'entry_type' => 'REGULAR',
                    'enrollment_type' => EnrollmentType::CONTINUING->value,
                    'notes' => $data['notes'] ?? "Individual {$action->value} from enrollment #{$sourceEnrollment->id}",
                ], $actorId, $idempotencyKey ? "D04-{$idempotencyKey}" : null);

                // Update PromotionItem
                $promotionItem->update([
                    'status' => PromotionItemStatus::SUCCEEDED,
                    'target_enrollment_id' => $newEnrollment->id,
                ]);

                // Update PromotionAttempt
                $attempt->update([
                    'status' => 'SUCCEEDED',
                    'finished_at' => now(),
                    'result_json' => [
                        'target_enrollment_id' => $newEnrollment->id,
                        'target_status' => $newEnrollment->status->value,
                    ],
                ]);

                // Update Batch
                $batch->update([
                    'status' => PromotionBatchStatus::COMPLETED,
                    'finished_at' => now(),
                ]);

                // Record Event
                PromotionEvent::create([
                    'promotion_batch_id' => $batch->id,
                    'promotion_item_id' => $promotionItem->id,
                    'actor_user_id' => $actorId,
                    'action' => 'INDIVIDUAL_PROMOTION_SUCCEEDED',
                    'after_json' => [
                        'item_id' => $promotionItem->id,
                        'target_enrollment_id' => $newEnrollment->id,
                    ],
                    'occurred_at' => now(),
                ]);

                $responseResult = [
                    'message' => "Student successfully {$action->value}ed to target period and section.",
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'promotion_item_id' => $promotionItem->id,
                    'source_enrollment_id' => $sourceEnrollment->id,
                    'target_enrollment_id' => $newEnrollment->id,
                    'status' => PromotionItemStatus::SUCCEEDED->value,
                    'target_enrollment' => [
                        'id' => $newEnrollment->id,
                        'status' => $newEnrollment->status->value,
                        'enrollment_type' => $newEnrollment->enrollment_type->value,
                        'period_id' => $newEnrollment->academic_period_id,
                        'section_id' => $newEnrollment->offering_section_id,
                    ],
                ];

                if ($idempotencyKey) {
                    PromotionIdempotencyKey::where('scope', 'INDIVIDUAL_PROMOTION')
                        ->where('key', $idempotencyKey)
                        ->update([
                            'state' => 'COMPLETED',
                            'outcome_ref' => (string) $batch->id,
                            'response_code' => 201,
                            'response_body' => $responseResult,
                        ]);
                }

                return $responseResult;

            } catch (Exception $e) {
                $promotionItem->update([
                    'status' => PromotionItemStatus::FAILED,
                    'error_code' => 'EXECUTION_FAILED',
                    'safe_error_message' => $e->getMessage(),
                ]);

                $attempt->update([
                    'status' => 'FAILED',
                    'finished_at' => now(),
                    'error_code' => 'EXECUTION_FAILED',
                    'result_json' => ['error' => $e->getMessage()],
                ]);

                $batch->update([
                    'status' => PromotionBatchStatus::COMPLETED_WITH_ERRORS,
                    'finished_at' => now(),
                ]);

                PromotionEvent::create([
                    'promotion_batch_id' => $batch->id,
                    'promotion_item_id' => $promotionItem->id,
                    'actor_user_id' => $actorId,
                    'action' => 'INDIVIDUAL_PROMOTION_FAILED',
                    'after_json' => ['error' => $e->getMessage()],
                    'occurred_at' => now(),
                ]);

                if ($idempotencyKey) {
                    PromotionIdempotencyKey::where('scope', 'INDIVIDUAL_PROMOTION')
                        ->where('key', $idempotencyKey)
                        ->update([
                            'state' => 'FAILED',
                            'response_code' => 422,
                            'response_body' => ['error' => $e->getMessage()],
                        ]);
                }

                throw $e;
            }
        });
    }
}

