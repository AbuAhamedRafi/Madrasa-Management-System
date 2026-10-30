<?php

namespace App\Services\Admissions;

use App\Enums\AdmissionStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\AdmissionsDomainException;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\EnrollmentStatusEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdmissionApprovalService
{
    /**
     * Mark an enrollment as fully approved once financial settlement is cleared (Integration Seam with D07).
     *
     * @throws AdmissionsDomainException
     */
    public function approveIfRequiredChargesSettled(int $enrollmentId, string $paymentReference, int $actorId): Enrollment
    {
        $enrollment = Enrollment::with('programAdmission')->findOrFail($enrollmentId);

        // Idempotent: if already approved, return
        if ($enrollment->status === EnrollmentStatus::APPROVED) {
            return $enrollment;
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING_PAYMENT && $enrollment->status !== EnrollmentStatus::PENDING_BILLING) {
            throw AdmissionsDomainException::invalidState("Cannot approve enrollment in status {$enrollment->status->value}.");
        }

        return DB::transaction(function () use ($enrollment, $paymentReference, $actorId) {
            $prevStatus = $enrollment->status->value;

            $enrollment->status = EnrollmentStatus::APPROVED;
            $enrollment->billing_cleared = true;
            $enrollment->payment_cleared = true;
            $enrollment->approved_at = now();
            $enrollment->lock_version += 1;
            $enrollment->updated_by = $actorId;
            $enrollment->save();

            // Also activate the program admission if pending
            if ($enrollment->programAdmission && $enrollment->programAdmission->status !== AdmissionStatus::ACTIVE) {
                $enrollment->programAdmission->status = AdmissionStatus::ACTIVE;
                $enrollment->programAdmission->lock_version += 1;
                $enrollment->programAdmission->updated_by = $actorId;
                $enrollment->programAdmission->save();
            }

            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => $prevStatus,
                'to_status' => EnrollmentStatus::APPROVED->value,
                'reason' => "Financial clearance completed: {$paymentReference}",
                'actor_id' => $actorId,
                'source_type' => 'PAYMENT_SEAM',
                'source_reference' => $paymentReference,
                'occurred_at' => now(),
            ]);

            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentApproved',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'payment_reference' => $paymentReference,
                    'status' => EnrollmentStatus::APPROVED->value,
                    'approved_at' => $enrollment->approved_at->toISOString(),
                ],
                'created_at' => now(),
            ]);

            return $enrollment->fresh();
        });
    }
}
