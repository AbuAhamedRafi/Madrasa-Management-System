<?php

namespace App\Services\Admissions;

use App\Enums\AdmissionEntryType;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use App\Exceptions\AdmissionsDomainException;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\EnrollmentRequestKey;
use App\Models\EnrollmentStatusEvent;
use App\Models\OfferingSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentPlacementService
{
    public function __construct(
        protected AdmissionEligibilityService $eligibilityService,
        protected ProgramAdmissionService $admissionService,
        protected SeatAllocationService $seatAllocationService
    ) {}

    /**
     * Create a new enrollment record starting strictly in PENDING_BILLING status.
     *
     * @throws AdmissionsDomainException
     */
    public function createEnrollment(array $data, int $actorId, ?string $idempotencyKey = null): Enrollment
    {
        $studentRecordId = (int) $data['student_record_id'];
        $programId = (int) $data['academic_program_id'];
        $periodId = (int) $data['academic_period_id'];
        $sectionId = (int) $data['offering_section_id'];
        $cohortId = isset($data['academic_cohort_id']) ? (int) $data['academic_cohort_id'] : null;
        $entryType = isset($data['entry_type'])
            ? AdmissionEntryType::from($data['entry_type'])
            : AdmissionEntryType::REGULAR;
        $enrollmentType = isset($data['enrollment_type'])
            ? EnrollmentType::from($data['enrollment_type'])
            : EnrollmentType::NEW;

        // Idempotency check if key provided
        if ($idempotencyKey) {
            $reqHash = hash('sha256', json_encode($data));
            $existingKey = EnrollmentRequestKey::where('actor_id', $actorId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingKey && $existingKey->state === 'COMPLETED' && $existingKey->enrollment_id) {
                $cached = Enrollment::with(['programAdmission', 'period', 'offering.programClass', 'section', 'student'])
                    ->find($existingKey->enrollment_id);
                if ($cached) {
                    return $cached;
                }
            }
        }

        // Validate eligibility
        $eligibility = $this->eligibilityService->checkEligibility($studentRecordId, $programId, $periodId, $cohortId);
        if (!$eligibility['is_eligible']) {
            $firstError = $eligibility['errors'][0];
            throw AdmissionsDomainException::ineligible(
                $firstError['code'],
                $firstError['message'],
                ['errors' => $eligibility['errors']]
            );
        }

        return DB::transaction(function () use ($data, $studentRecordId, $programId, $periodId, $sectionId, $cohortId, $entryType, $enrollmentType, $actorId, $idempotencyKey) {
            // 1. Resolve or create ProgramAdmission
            $admission = $this->admissionService->resolveOrCreateAdmission(
                $studentRecordId,
                $programId,
                $cohortId,
                $entryType,
                $actorId
            );

            // 2. Lock section row & claim seat (INV-05)
            $section = $this->seatAllocationService->claimSeat($sectionId);

            // 3. Create enrollment in PENDING_BILLING
            $enrollment = Enrollment::create([
                'program_admission_id' => $admission->id,
                'student_id' => $studentRecordId,
                'academic_period_id' => $periodId,
                'academic_offering_id' => $section->academic_offering_id,
                'offering_section_id' => $section->id,
                'enrollment_type' => $enrollmentType,
                'status' => EnrollmentStatus::PENDING_BILLING,
                'billing_cleared' => false,
                'payment_cleared' => false,
                'lock_version' => 1,
                'submitted_at' => now(),
                'created_by' => $actorId,
            ]);

            // 4. Log status event
            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'DRAFT',
                'to_status' => EnrollmentStatus::PENDING_BILLING->value,
                'reason' => $data['notes'] ?? 'Initial enrollment submission awaiting billing clearance',
                'actor_id' => $actorId,
                'source_type' => 'USER_ACTION',
                'source_reference' => 'ENROLLMENT_SUBMIT',
                'occurred_at' => now(),
            ]);

            // 5. Emit Outbox Event
            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentSubmitted',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $studentRecordId,
                    'program_id' => $programId,
                    'academic_period_id' => $periodId,
                    'offering_section_id' => $section->id,
                    'academic_offering_id' => $section->academic_offering_id,
                    'status' => EnrollmentStatus::PENDING_BILLING->value,
                    'actor_id' => $actorId,
                ],
                'created_at' => now(),
            ]);

            // 6. Record idempotency key if present
            if ($idempotencyKey) {
                EnrollmentRequestKey::updateOrCreate(
                    ['actor_id' => $actorId, 'idempotency_key' => $idempotencyKey],
                    [
                        'request_hash' => hash('sha256', json_encode($data)),
                        'enrollment_id' => $enrollment->id,
                        'state' => 'COMPLETED',
                        'response_code' => 201,
                    ]
                );
            }

            return $enrollment->load(['programAdmission', 'period', 'offering.programClass', 'section', 'student']);
        });
    }

    /**
     * Cancel an enrollment and release the section seat.
     *
     * @throws AdmissionsDomainException
     */
    public function cancelEnrollment(Enrollment $enrollment, string $reason, int $actorId): Enrollment
    {
        if ($enrollment->status === EnrollmentStatus::CANCELLED || $enrollment->status === EnrollmentStatus::REJECTED) {
            throw AdmissionsDomainException::invalidState("Enrollment is already {$enrollment->status->value}.");
        }

        return DB::transaction(function () use ($enrollment, $reason, $actorId) {
            $prevStatus = $enrollment->status->value;

            $enrollment->status = EnrollmentStatus::CANCELLED;
            $enrollment->cancelled_at = now();
            $enrollment->lock_version += 1;
            $enrollment->updated_by = $actorId;
            $enrollment->save();

            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => $prevStatus,
                'to_status' => EnrollmentStatus::CANCELLED->value,
                'reason' => $reason,
                'actor_id' => $actorId,
                'source_type' => 'USER_ACTION',
                'source_reference' => 'ENROLLMENT_CANCEL',
                'occurred_at' => now(),
            ]);

            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentCancelled',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'offering_section_id' => $enrollment->offering_section_id,
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
                'created_at' => now(),
            ]);

            return $enrollment->fresh(['section', 'statusEvents']);
        });
    }

    /**
     * Reject an enrollment with administrative reason.
     *
     * @throws AdmissionsDomainException
     */
    public function rejectEnrollment(Enrollment $enrollment, string $reason, int $actorId): Enrollment
    {
        if ($enrollment->status->isTerminal()) {
            throw AdmissionsDomainException::invalidState("Cannot reject enrollment in terminal status {$enrollment->status->value}.");
        }

        return DB::transaction(function () use ($enrollment, $reason, $actorId) {
            $prevStatus = $enrollment->status->value;

            $enrollment->status = EnrollmentStatus::REJECTED;
            $enrollment->lock_version += 1;
            $enrollment->updated_by = $actorId;
            $enrollment->save();

            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => $prevStatus,
                'to_status' => EnrollmentStatus::REJECTED->value,
                'reason' => $reason,
                'actor_id' => $actorId,
                'source_type' => 'USER_ACTION',
                'source_reference' => 'ENROLLMENT_REJECT',
                'occurred_at' => now(),
            ]);

            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentRejected',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'offering_section_id' => $enrollment->offering_section_id,
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
                'created_at' => now(),
            ]);

            return $enrollment->fresh(['section', 'statusEvents']);
        });
    }
}

