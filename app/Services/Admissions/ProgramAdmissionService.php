<?php

namespace App\Services\Admissions;

use App\Enums\AdmissionEntryType;
use App\Enums\AdmissionStatus;
use App\Models\ProgramAdmission;

class ProgramAdmissionService
{
    /**
     * Resolve existing active admission or create a new program admission record.
     */
    public function resolveOrCreateAdmission(
        int $studentId,
        int $programId,
        ?int $cohortId,
        AdmissionEntryType $entryType,
        int $actorId
    ): ProgramAdmission {
        /** @var ProgramAdmission|null $existing */
        $existing = ProgramAdmission::where('student_id', $studentId)
            ->where('academic_program_id', $programId)
            ->whereIn('status', [AdmissionStatus::ACTIVE->value, AdmissionStatus::PENDING_ACTIVATION->value])
            ->first();

        if ($existing) {
            if ($cohortId && !$existing->academic_cohort_id) {
                $existing->academic_cohort_id = $cohortId;
                $existing->updated_by = $actorId;
                $existing->save();
            }
            return $existing;
        }

        return ProgramAdmission::create([
            'student_id' => $studentId,
            'academic_program_id' => $programId,
            'academic_cohort_id' => $cohortId,
            'entry_type' => $entryType,
            'status' => AdmissionStatus::PENDING_ACTIVATION,
            'entered_at' => now(),
            'lock_version' => 1,
            'created_by' => $actorId,
        ]);
    }
}

