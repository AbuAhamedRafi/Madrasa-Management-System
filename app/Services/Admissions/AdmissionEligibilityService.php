<?php

namespace App\Services\Admissions;

use App\Enums\AcademicScheme;
use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Exceptions\AdmissionsDomainException;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Enrollment;
use App\Models\ProgramAdmission;
use App\Models\Student;

class AdmissionEligibilityService
{
    /**
     * Check if a student is eligible for admission/enrollment into a program and optional period.
     */
    public function checkEligibility(int $studentId, int $programId, ?int $periodId = null, ?int $cohortId = null): array
    {
        $errors = [];
        $warnings = [];

        $student = Student::with(['duplicateReviews'])->find($studentId);
        if (!$student) {
            throw AdmissionsDomainException::ineligible('STUDENT_NOT_FOUND', "Student record #{$studentId} not found.", ['student_id' => $studentId]);
        }

        // INV-02: Student must be in ACTIVE status
        if ($student->status !== StudentStatus::ACTIVE) {
            $errors[] = [
                'code' => 'STUDENT_NOT_ACTIVE',
                'message' => "Student #{$student->student_id} is in status {$student->status->value}, not ACTIVE.",
            ];
        }

        // Duplicate review pending check
        $pendingReviews = $student->duplicateReviews->where('status', 'PENDING')->count();
        if ($pendingReviews > 0) {
            $errors[] = [
                'code' => 'STUDENT_DUPLICATE_REVIEW_PENDING',
                'message' => "Student #{$student->student_id} has {$pendingReviews} unresolved duplicate review ticket(s).",
            ];
        }

        // Program existence and status
        $program = AcademicProgram::with(['department', 'educationLevel'])->find($programId);
        if (!$program) {
            throw AdmissionsDomainException::ineligible('PROGRAM_NOT_FOUND', "Academic program #{$programId} not found.", ['program_id' => $programId]);
        }

        if ($program->state->value === 'INACTIVE') {
            $errors[] = [
                'code' => 'PROGRAM_INACTIVE',
                'message' => "Program {$program->code} is currently INACTIVE.",
            ];
        }

        // INV-04: Scheme-specific invariants
        if ($program->scheme === AcademicScheme::CALENDAR_YEAR) {
            if ($cohortId !== null) {
                $errors[] = [
                    'code' => 'COHORT_NOT_ALLOWED',
                    'message' => "Calendar-Year programs (such as {$program->code}) cannot have a cohort assigned.",
                ];
            }
        } elseif ($program->scheme === AcademicScheme::SESSION_BASED) {
            // Check if existing admission has cohort, or cohortId was supplied
            $existingAdmission = ProgramAdmission::where('student_id', $studentId)
                ->where('academic_program_id', $programId)
                ->whereIn('status', ['ACTIVE', 'PENDING_ACTIVATION'])
                ->first();

            $effectiveCohortId = $cohortId ?? $existingAdmission?->academic_cohort_id;
            if (!$effectiveCohortId) {
                $errors[] = [
                    'code' => 'COHORT_REQUIRED',
                    'message' => "Session-based programs (such as {$program->code}) require an academic cohort.",
                ];
            }
        }

        // Period checks if periodId is provided
        if ($periodId !== null) {
            $period = AcademicPeriod::find($periodId);
            if (!$period) {
                $errors[] = [
                    'code' => 'PERIOD_NOT_FOUND',
                    'message' => "Study period #{$periodId} not found.",
                ];
            } else {
                if ($period->program_id !== $programId) {
                    $errors[] = [
                        'code' => 'PERIOD_PROGRAM_MISMATCH',
                        'message' => "Period {$period->label} does not belong to program {$program->code}.",
                    ];
                }

                if ($period->state->value === 'CLOSED') {
                    $errors[] = [
                        'code' => 'PERIOD_CLOSED',
                        'message' => "Study period {$period->label} is CLOSED.",
                    ];
                }

                // Scheme cohort matching
                if ($program->scheme === AcademicScheme::SESSION_BASED && isset($effectiveCohortId)) {
                    if ($period->cohort_id !== $effectiveCohortId) {
                        $errors[] = [
                            'code' => 'PERIOD_COHORT_MISMATCH',
                            'message' => "Period cohort #{$period->cohort_id} does not match student cohort #{$effectiveCohortId}.",
                        ];
                    }
                }

                // INV-03: Active/pending enrollment conflict check
                $existingEnrollment = Enrollment::where('student_id', $studentId)
                    ->where('academic_period_id', $periodId)
                    ->whereIn('status', [
                        EnrollmentStatus::DRAFT->value,
                        EnrollmentStatus::PENDING_BILLING->value,
                        EnrollmentStatus::PENDING_PAYMENT->value,
                        EnrollmentStatus::APPROVED->value,
                    ])
                    ->first();

                if ($existingEnrollment) {
                    $errors[] = [
                        'code' => 'ALREADY_ENROLLED_IN_PERIOD',
                        'message' => "Student is already enrolled in this period with status {$existingEnrollment->status->value} (Enrollment #{$existingEnrollment->id}).",
                    ];
                }
            }
        }

        return [
            'is_eligible' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name_bn' => $student->name_bn,
                'name_en' => $student->name_en,
                'status' => $student->status->value,
            ],
            'program' => [
                'id' => $program->id,
                'code' => $program->code,
                'name_bn' => $program->name_bn,
                'name_en' => $program->name_en,
                'scheme' => $program->scheme->value,
            ],
        ];
    }
}
