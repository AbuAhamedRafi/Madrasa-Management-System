<?php

namespace App\Services\Admissions;

use App\Enums\EnrollmentStatus;
use App\Exceptions\AdmissionsDomainException;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\EnrollmentStatusEvent;
use App\Models\OfferingSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeatAllocationService
{
    /**
     * Check current capacity and occupancy for a section.
     */
    public function checkCapacity(int $sectionId): array
    {
        $section = OfferingSection::with('offering.programClass')->findOrFail($sectionId);

        $occupied = Enrollment::where('offering_section_id', $sectionId)
            ->whereIn('status', [
                EnrollmentStatus::PENDING_BILLING->value,
                EnrollmentStatus::PENDING_PAYMENT->value,
                EnrollmentStatus::APPROVED->value,
            ])
            ->count();

        return [
            'section_id' => $section->id,
            'section_code' => $section->code,
            'section_name_bn' => $section->name_bn,
            'section_name_en' => $section->name_en,
            'capacity' => $section->capacity,
            'occupied_seats' => $occupied,
            'available_seats' => max(0, $section->capacity - $occupied),
            'is_full' => $occupied >= $section->capacity,
            'is_active' => $section->is_active,
        ];
    }

    /**
     * Atomically claim a seat in a section using PostgreSQL row-level lock.
     * Must be invoked within an active DB transaction.
     *
     * @throws AdmissionsDomainException
     */
    public function claimSeat(int $sectionId): OfferingSection
    {
        /** @var OfferingSection|null $section */
        $section = OfferingSection::where('id', $sectionId)
            ->lockForUpdate()
            ->first();

        if (!$section) {
            throw AdmissionsDomainException::ineligible('SECTION_NOT_FOUND', "Section #{$sectionId} not found.", ['section_id' => $sectionId]);
        }

        if (!$section->is_active) {
            throw AdmissionsDomainException::ineligible('SECTION_INACTIVE', "Section {$section->code} is inactive.", ['section_id' => $sectionId]);
        }

        $occupied = Enrollment::where('offering_section_id', $sectionId)
            ->whereIn('status', [
                EnrollmentStatus::PENDING_BILLING->value,
                EnrollmentStatus::PENDING_PAYMENT->value,
                EnrollmentStatus::APPROVED->value,
            ])
            ->count();

        if ($occupied >= $section->capacity) {
            throw AdmissionsDomainException::noSeatsAvailable($sectionId, $section->capacity);
        }

        return $section;
    }

    /**
     * Reallocate an existing enrollment to a new section avoiding deadlocks via sorted row locks.
     *
     * @throws AdmissionsDomainException
     */
    public function reallocateSeat(Enrollment $enrollment, int $newSectionId, string $reason, int $actorId): Enrollment
    {
        if ($enrollment->status->isTerminal()) {
            throw AdmissionsDomainException::invalidState("Cannot reallocate enrollment in terminal state {$enrollment->status->value}.");
        }

        $oldSectionId = $enrollment->offering_section_id;
        if ($oldSectionId === $newSectionId) {
            return $enrollment;
        }

        return DB::transaction(function () use ($enrollment, $oldSectionId, $newSectionId, $reason, $actorId) {
            // INV-06: Deterministic sorted ID lock order to prevent deadlocks
            $firstId = min($oldSectionId, $newSectionId);
            $secondId = max($oldSectionId, $newSectionId);

            $sections = OfferingSection::whereIn('id', [$firstId, $secondId])
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $newSection = $sections->get($newSectionId);
            if (!$newSection || !$newSection->is_active) {
                throw AdmissionsDomainException::ineligible('SECTION_INACTIVE', "Destination section is not active.", ['section_id' => $newSectionId]);
            }

            // Verify capacity in destination section
            $occupiedInNew = Enrollment::where('offering_section_id', $newSectionId)
                ->whereIn('status', [
                    EnrollmentStatus::PENDING_BILLING->value,
                    EnrollmentStatus::PENDING_PAYMENT->value,
                    EnrollmentStatus::APPROVED->value,
                ])
                ->count();

            if ($occupiedInNew >= $newSection->capacity) {
                throw AdmissionsDomainException::noSeatsAvailable($newSectionId, $newSection->capacity);
            }

            // Update placement
            $enrollment->offering_section_id = $newSectionId;
            $enrollment->academic_offering_id = $newSection->academic_offering_id;
            $enrollment->updated_by = $actorId;
            $enrollment->lock_version += 1;
            $enrollment->save();

            // Log status event
            EnrollmentStatusEvent::create([
                'enrollment_id' => $enrollment->id,
                'from_status' => $enrollment->status->value,
                'to_status' => $enrollment->status->value,
                'reason' => "Reallocated from Section #{$oldSectionId} to Section #{$newSectionId}: {$reason}",
                'actor_id' => $actorId,
                'source_type' => 'USER_ACTION',
                'source_reference' => "REALLOCATE_{$oldSectionId}_{$newSectionId}",
                'occurred_at' => now(),
            ]);

            // Outbox event
            EnrollmentOutbox::create([
                'id' => Str::uuid()->toString(),
                'event_type' => 'EnrollmentSectionReallocated',
                'aggregate_type' => 'Enrollment',
                'aggregate_id' => $enrollment->id,
                'payload_jsonb' => [
                    'enrollment_id' => $enrollment->id,
                    'previous_section_id' => $oldSectionId,
                    'new_section_id' => $newSectionId,
                    'reason' => $reason,
                    'actor_id' => $actorId,
                ],
                'created_at' => now(),
            ]);

            return $enrollment->fresh(['section', 'offering.programClass', 'student']);
        });
    }
}

