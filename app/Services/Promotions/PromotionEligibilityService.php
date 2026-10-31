<?php

namespace App\Services\Promotions;

use App\Enums\AcademicScheme;
use App\Enums\EnrollmentStatus;
use App\Enums\PeriodState;
use App\Enums\PromotionAction;
use App\Enums\PromotionItemStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\OfferingSection;
use App\Models\ProgramClass;
use App\Models\PromotionItem;
use App\Models\Student;

class PromotionEligibilityService
{
    /**
     * Evaluate eligibility for a student progression from a source enrollment to a target section.
     * This method is strictly read-only and produces no database mutations.
     *
     * @param int $studentId
     * @param int $sourceEnrollmentId
     * @param int $targetOfferingSectionId
     * @param int $targetAcademicPeriodId
     * @param PromotionAction $action
     * @param bool $academicEligibilityConfirmed
     * @param bool $isExecution
     * @return array
     */
    public function evaluate(
        int $studentId,
        int $sourceEnrollmentId,
        int $targetOfferingSectionId,
        int $targetAcademicPeriodId,
        PromotionAction $action = PromotionAction::PROMOTE,
        bool $academicEligibilityConfirmed = false,
        bool $isExecution = false
    ): array {
        $errors = [];
        $warnings = [];

        // 1. EL-01: Student check
        $student = Student::with(['duplicateReviews'])->find($studentId);
        if (!$student) {
            return [
                'is_eligible' => false,
                'action' => $action->value,
                'errors' => [
                    ['code' => 'STUDENT_NOT_FOUND', 'message' => "Student record #{$studentId} not found."]
                ],
                'warnings' => [],
            ];
        }

        // Student active check (EL-03)
        if ($student->status !== StudentStatus::ACTIVE) {
            $errors[] = [
                'code' => 'STUDENT_NOT_ACTIVE',
                'message' => "Student #{$student->student_id} is in status {$student->status->value}, not ACTIVE.",
            ];
        }

        // Duplicate review check
        $pendingReviews = $student->duplicateReviews->where('status', 'PENDING')->count();
        if ($pendingReviews > 0) {
            $errors[] = [
                'code' => 'STUDENT_DUPLICATE_REVIEW_PENDING',
                'message' => "Student #{$student->student_id} has {$pendingReviews} unresolved duplicate review ticket(s).",
            ];
        }

        // 2. EL-01 & EL-02: Source Enrollment check
        $sourceEnrollment = Enrollment::with([
            'programAdmission.program',
            'period',
            'offering.programClass',
            'section',
        ])->find($sourceEnrollmentId);

        if (!$sourceEnrollment) {
            $errors[] = [
                'code' => 'SOURCE_ENROLLMENT_NOT_FOUND',
                'message' => "Source enrollment #{$sourceEnrollmentId} not found.",
            ];
        } else {
            if ((int) $sourceEnrollment->student_id !== (int) $studentId) {
                $errors[] = [
                    'code' => 'ENROLLMENT_STUDENT_MISMATCH',
                    'message' => "Source enrollment #{$sourceEnrollmentId} does not belong to student #{$studentId}.",
                ];
            }

            if ($sourceEnrollment->status !== EnrollmentStatus::APPROVED) {
                $errors[] = [
                    'code' => 'SOURCE_NOT_APPROVED',
                    'message' => "Source enrollment must be in APPROVED status. Current status is {$sourceEnrollment->status->value}.",
                ];
            }

            if (!$sourceEnrollment->programAdmission || $sourceEnrollment->programAdmission->status->value !== 'ACTIVE') {
                $statusVal = $sourceEnrollment->programAdmission ? $sourceEnrollment->programAdmission->status->value : 'NONE';
                $errors[] = [
                    'code' => 'SOURCE_INELIGIBLE',
                    'message' => "Source program admission is not active (current status: {$statusVal}).",
                ];
            }
        }

        // 3. EL-09: Source enrollment already has a succeeded promotion item
        $alreadyPromoted = PromotionItem::where('source_enrollment_id', $sourceEnrollmentId)
            ->where('status', PromotionItemStatus::SUCCEEDED->value)
            ->exists();

        if ($alreadyPromoted) {
            $errors[] = [
                'code' => 'ALREADY_PROMOTED',
                'message' => "Source enrollment #{$sourceEnrollmentId} has already been promoted or progressed.",
            ];
        }

        // 4. EL-06: Target Academic Period check
        $targetPeriod = AcademicPeriod::with('program')->find($targetAcademicPeriodId);
        if (!$targetPeriod) {
            $errors[] = [
                'code' => 'TARGET_PERIOD_NOT_FOUND',
                'message' => "Target academic period #{$targetAcademicPeriodId} not found.",
            ];
        } else {
            if ($targetPeriod->state !== PeriodState::OPEN) {
                $errors[] = [
                    'code' => 'TARGET_PERIOD_CLOSED',
                    'message' => "Target academic period '{$targetPeriod->label}' is not in OPEN state (currently {$targetPeriod->state->value}).",
                ];
            }

            if ($sourceEnrollment && (int) $sourceEnrollment->academic_period_id === (int) $targetAcademicPeriodId) {
                $errors[] = [
                    'code' => 'INVALID_TARGET_PERIOD',
                    'message' => "Target academic period cannot be identical to the source academic period.",
                ];
            }

            if ($sourceEnrollment && $sourceEnrollment->period && $targetPeriod->start_date < $sourceEnrollment->period->start_date) {
                $errors[] = [
                    'code' => 'INVALID_TARGET_PERIOD',
                    'message' => "Target academic period starts before the source academic period.",
                ];
            }
        }

        // 5. Target Offering & Section check (EL-01, EL-05, EL-08)
        $targetSection = OfferingSection::with(['offering.programClass.program', 'offering.period'])
            ->find($targetOfferingSectionId);

        if (!$targetSection) {
            $errors[] = [
                'code' => 'SECTION_NOT_FOUND',
                'message' => "Target offering section #{$targetOfferingSectionId} not found.",
            ];
        } else {
            if (!$targetSection->is_active) {
                $errors[] = [
                    'code' => 'SECTION_INACTIVE',
                    'message' => "Target offering section is not active.",
                ];
            }

            $targetOffering = $targetSection->offering;
            if (!$targetOffering || (int) $targetOffering->academic_period_id !== (int) $targetAcademicPeriodId) {
                $errors[] = [
                    'code' => 'PERIOD_OFFERING_MISMATCH',
                    'message' => "Target offering section does not belong to the selected target academic period.",
                ];
            }

            // Program match check (EL-05)
            if ($sourceEnrollment && $sourceEnrollment->offering && $targetOffering) {
                $sourceProgramId = $sourceEnrollment->offering->programClass->program_id;
                $targetProgramId = $targetOffering->programClass->program_id;

                if ((int) $sourceProgramId !== (int) $targetProgramId) {
                    $errors[] = [
                        'code' => 'PROGRAM_MISMATCH',
                        'message' => "Target offering program #{$targetProgramId} does not match source program #{$sourceProgramId}.",
                    ];
                }

                // Session-based cohort match check
                $program = $sourceEnrollment->offering->programClass->program;
                if ($program && $program->scheme === AcademicScheme::SESSION_BASED) {
                    $sourceCohortId = $sourceEnrollment->period->cohort_id ?? $sourceEnrollment->programAdmission->academic_cohort_id;
                    $targetCohortId = $targetPeriod?->cohort_id;

                    if ((int) $sourceCohortId !== (int) $targetCohortId) {
                        $errors[] = [
                            'code' => 'PROGRAM_COHORT_MISMATCH',
                            'message' => "Session-based progression requires matching cohort. Source cohort is #{$sourceCohortId}, but target is #{$targetCohortId}.",
                        ];
                    }
                }
            }

            // Stage Transition check (EL-07)
            if ($sourceEnrollment && $sourceEnrollment->offering && $targetOffering) {
                $sourceClass = $sourceEnrollment->offering->programClass;
                $targetClass = $targetOffering->programClass;

                $sourceStage = (int) $sourceClass->stage_number;
                $targetStage = (int) $targetClass->stage_number;

                if ($action === PromotionAction::PROMOTE) {
                    $maxStage = ProgramClass::where('program_id', $sourceClass->program_id)->max('stage_number');
                    if ($sourceStage >= (int) $maxStage) {
                        $errors[] = [
                            'code' => 'ALREADY_AT_HIGHEST_STAGE',
                            'message' => "Student is already in highest stage ({$sourceClass->name_en}) for program {$sourceClass->program->name_en}.",
                        ];
                    } elseif ($targetStage !== $sourceStage + 1) {
                        $errors[] = [
                            'code' => 'INVALID_STAGE_TRANSITION',
                            'message' => "Promotion requires advancing to stage ".($sourceStage + 1).", but target section is in stage {$targetStage}.",
                        ];
                    }
                } elseif ($action === PromotionAction::REPEAT) {
                    if ($targetStage !== $sourceStage) {
                        $errors[] = [
                            'code' => 'INVALID_STAGE_TRANSITION',
                            'message' => "Repeat requires retaining same stage ({$sourceStage}), but target section is in stage {$targetStage}.",
                        ];
                    }
                }
            }

            // Section Capacity check (EL-08)
            $activeCount = Enrollment::where('offering_section_id', $targetSection->id)
                ->whereIn('status', [
                    EnrollmentStatus::PENDING_BILLING,
                    EnrollmentStatus::PENDING_PAYMENT,
                    EnrollmentStatus::APPROVED,
                ])
                ->count();

            if ($activeCount >= (int) $targetSection->capacity) {
                $errors[] = [
                    'code' => 'SECTION_FULL',
                    'message' => "Section {$targetSection->code} is at full capacity ({$activeCount}/{$targetSection->capacity}).",
                    'details' => [
                        'capacity' => $targetSection->capacity,
                        'current_enrolled' => $activeCount,
                    ],
                ];
            }
        }

        // 6. EL-09: Target Duplicate Enrollment check
        $targetExists = Enrollment::where('student_id', $studentId)
            ->where('academic_period_id', $targetAcademicPeriodId)
            ->whereIn('status', [
                EnrollmentStatus::PENDING_BILLING,
                EnrollmentStatus::PENDING_PAYMENT,
                EnrollmentStatus::APPROVED,
            ])
            ->exists();

        if ($targetExists) {
            $errors[] = [
                'code' => 'TARGET_EXISTS',
                'message' => "Student already possesses an active or pending enrollment in the target academic period.",
            ];
        }

        // 7. EL-04: Academic eligibility confirmation check
        if (!$academicEligibilityConfirmed) {
            if ($isExecution) {
                $errors[] = [
                    'code' => 'ELIGIBILITY_NOT_CONFIRMED',
                    'message' => "Academic eligibility confirmation is required to execute promotion.",
                ];
            } else {
                $warnings[] = [
                    'code' => 'ELIGIBILITY_NOT_YET_CONFIRMED',
                    'message' => "Academic eligibility has not been confirmed yet.",
                ];
            }
        }

        return [
            'is_eligible' => empty($errors),
            'action' => $action->value,
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name_en' => $student->name_en,
                'name_bn' => $student->name_bn,
                'status' => $student->status->value,
            ],
            'source_enrollment' => $sourceEnrollment ? [
                'id' => $sourceEnrollment->id,
                'status' => $sourceEnrollment->status->value,
                'period_label' => $sourceEnrollment->period?->label,
                'program_name' => $sourceEnrollment->offering?->programClass?->program?->name_en,
                'class_name' => $sourceEnrollment->offering?->programClass?->name_en,
                'stage_number' => $sourceEnrollment->offering?->programClass?->stage_number,
                'section_code' => $sourceEnrollment->section?->code,
            ] : null,
            'target_period' => $targetPeriod ? [
                'id' => $targetPeriod->id,
                'label' => $targetPeriod->label,
                'state' => $targetPeriod->state->value,
            ] : null,
            'target_offering_section' => $targetSection ? [
                'id' => $targetSection->id,
                'code' => $targetSection->code,
                'capacity' => $targetSection->capacity,
                'class_name' => $targetSection->offering?->programClass?->name_en,
                'stage_number' => $targetSection->offering?->programClass?->stage_number,
            ] : null,
            'academic_eligibility_confirmed' => $academicEligibilityConfirmed,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}

