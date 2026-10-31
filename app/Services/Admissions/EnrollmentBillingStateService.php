<?php

namespace App\Services\Admissions;

use App\Enums\EnrollmentStatus;
use App\Exceptions\AdmissionsDomainException;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\EnrollmentStatusEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnrollmentBillingStateService
{
    /**
     * Mark an enrollment as billed and advance state to PENDING_PAYMENT (Integration Seam with D06).
     *
     * @throws AdmissionsDomainException
     */
    public function markPrepared(int $enrollmentId, string $reference, int $actorId): Enrollment
    {
        $enrollment = Enrollment::findOrFail($enrollmentId);

        // Idempotent: if already advanced, return
        if ($enrollment->status === EnrollmentStatus::PENDING_PAYMENT || $enrollment->status === EnrollmentStatus::APPROVED) {
            return $enrollment;
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING_BILLING) {
            throw AdmissionsDomainException::invalidState("Cannot prepare billing for enrollment in status {$enrollment->status->value}.");
        }

        return DB::transaction(function () use ($enrollment, $reference, $actorId) {
            $prevStatus = $enrollment->status->value;

            $enrollment->status = EnrollmentStatus::PENDING_PAYMENT;
            $enrollment->billing_cleared = true;
            $enrollment->lock_version += 1;
            $enrollment->updated_by = $actorId;
            $enrollment->save();

            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => $prevStatus,
                'to_status' => EnrollmentStatus::PENDING_PAYMENT->value,
                'reason' => "Billing liability established: {$reference}",
                'actor_id' => $actorId,
                'source_type' => 'BILLING_SEAM',
                'source_reference' => $reference,
                'occurred_at' => now(),
            ]);

            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentBillingPrepared',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'reference' => $reference,
                    'status' => EnrollmentStatus::PENDING_PAYMENT->value,
                ],
                'created_at' => now(),
            ]);

            return $enrollment->fresh();
        });
    }
}

