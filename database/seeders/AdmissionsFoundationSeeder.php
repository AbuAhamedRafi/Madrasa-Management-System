<?php

namespace Database\Seeders;

use App\Enums\AdmissionEntryType;
use App\Enums\AdmissionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\EnrollmentStatusEvent;
use App\Models\OfferingSection;
use App\Models\ProgramAdmission;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdmissionsFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'superadmin')->first()
            ?? User::where('status', 'ACTIVE')->first();
        $adminId = $admin ? $admin->id : 1;

        $students = Student::where('status', 'ACTIVE')->orderBy('id')->get();
        if ($students->isEmpty()) {
            return;
        }

        // 1. Ebtedayee Admissions & Enrollments
        $ebtProgram = AcademicProgram::where('code', 'EBT')->first();
        if ($ebtProgram) {
            $ebtPeriod = AcademicPeriod::where('program_id', $ebtProgram->id)->where('calendar_year', 2026)->first();
            if ($ebtPeriod) {
                $ebtOfferings = AcademicOffering::where('academic_period_id', $ebtPeriod->id)->with(['sections', 'programClass'])->get();

                // Student 0: EBT-1 New Admission PENDING_BILLING
                if (isset($students[0]) && isset($ebtOfferings[0])) {
                    $this->seedEnrollment(
                        $students[0],
                        $ebtProgram,
                        null,
                        $ebtPeriod,
                        $ebtOfferings[0],
                        $ebtOfferings[0]->sections->first(),
                        AdmissionEntryType::REGULAR,
                        EnrollmentType::NEW,
                        EnrollmentStatus::PENDING_BILLING,
                        false,
                        false,
                        $adminId,
                        'Initial admission in Ebtedayee Class 1 awaiting fee assessment'
                    );
                }

                // Student 1: EBT-1 Admission PENDING_PAYMENT
                if (isset($students[1]) && isset($ebtOfferings[0])) {
                    $this->seedEnrollment(
                        $students[1],
                        $ebtProgram,
                        null,
                        $ebtPeriod,
                        $ebtOfferings[0],
                        $ebtOfferings[0]->sections->first(),
                        AdmissionEntryType::REGULAR,
                        EnrollmentType::NEW,
                        EnrollmentStatus::PENDING_PAYMENT,
                        true,
                        false,
                        $adminId,
                        'Fee assessed, awaiting cash counter settlement'
                    );
                }

                // Student 2: EBT-2 Admission APPROVED
                if (isset($students[2]) && isset($ebtOfferings[1])) {
                    $this->seedEnrollment(
                        $students[2],
                        $ebtProgram,
                        null,
                        $ebtPeriod,
                        $ebtOfferings[1],
                        $ebtOfferings[1]->sections->first(),
                        AdmissionEntryType::REGULAR,
                        EnrollmentType::NEW,
                        EnrollmentStatus::APPROVED,
                        true,
                        true,
                        $adminId,
                        'Admission fully cleared and approved'
                    );
                }
            }
        }

        // 2. Dakhil Admissions & Enrollments
        $dkhProgram = AcademicProgram::where('code', 'DKH')->first();
        if ($dkhProgram) {
            $dkhPeriod = AcademicPeriod::where('program_id', $dkhProgram->id)->where('calendar_year', 2026)->first();
            if ($dkhPeriod) {
                $dkhOfferings = AcademicOffering::where('academic_period_id', $dkhPeriod->id)->with(['sections', 'programClass'])->get();

                // Student 3: Dakhil Class 6 PENDING_BILLING
                if (isset($students[3]) && isset($dkhOfferings[0])) {
                    $this->seedEnrollment(
                        $students[3],
                        $dkhProgram,
                        null,
                        $dkhPeriod,
                        $dkhOfferings[0],
                        $dkhOfferings[0]->sections->first(),
                        AdmissionEntryType::REGULAR,
                        EnrollmentType::NEW,
                        EnrollmentStatus::PENDING_BILLING,
                        false,
                        false,
                        $adminId,
                        'Dakhil Class 6 admission awaiting billing'
                    );
                }

                // Student 4: Dakhil Class 6 APPROVED
                if (isset($students[4]) && isset($dkhOfferings[0])) {
                    $this->seedEnrollment(
                        $students[4],
                        $dkhProgram,
                        null,
                        $dkhPeriod,
                        $dkhOfferings[0],
                        $dkhOfferings[0]->sections->first(),
                        AdmissionEntryType::REGULAR,
                        EnrollmentType::NEW,
                        EnrollmentStatus::APPROVED,
                        true,
                        true,
                        $adminId,
                        'Dakhil Class 6 admission completed'
                    );
                }
            }
        }

        // 3. Alim (Session-Based) Admissions & Enrollments
        $almProgram = AcademicProgram::where('code', 'ALM')->first();
        if ($almProgram) {
            $almCohort = $almProgram->cohorts()->first();
            if ($almCohort) {
                $almPeriod = AcademicPeriod::where('program_id', $almProgram->id)
                    ->where('cohort_id', $almCohort->id)
                    ->where('study_year_number', 1)
                    ->first();

                if ($almPeriod) {
                    $almOffering = AcademicOffering::where('academic_period_id', $almPeriod->id)->with('sections')->first();
                    if (isset($students[5]) && $almOffering && $almOffering->sections->isNotEmpty()) {
                        $this->seedEnrollment(
                            $students[5],
                            $almProgram,
                            $almCohort->id,
                            $almPeriod,
                            $almOffering,
                            $almOffering->sections->first(),
                            AdmissionEntryType::REGULAR,
                            EnrollmentType::NEW,
                            EnrollmentStatus::PENDING_BILLING,
                            false,
                            false,
                            $adminId,
                            'Alim 1st Year enrollment awaiting billing setup'
                        );
                    }
                }
            }
        }

        // 4. Fazil (Pass) Admissions & Enrollments
        $fzpProgram = AcademicProgram::where('code', 'FZ-P')->first();
        if ($fzpProgram) {
            $fzpCohort = $fzpProgram->cohorts()->first();
            if ($fzpCohort) {
                $fzpPeriod = AcademicPeriod::where('program_id', $fzpProgram->id)
                    ->where('cohort_id', $fzpCohort->id)
                    ->where('study_year_number', 1)
                    ->first();

                if ($fzpPeriod) {
                    $fzpOffering = AcademicOffering::where('academic_period_id', $fzpPeriod->id)->with('sections')->first();
                    if (isset($students[6]) && $fzpOffering && $fzpOffering->sections->isNotEmpty()) {
                        $this->seedEnrollment(
                            $students[6],
                            $fzpProgram,
                            $fzpCohort->id,
                            $fzpPeriod,
                            $fzpOffering,
                            $fzpOffering->sections->first(),
                            AdmissionEntryType::REGULAR,
                            EnrollmentType::NEW,
                            EnrollmentStatus::APPROVED,
                            true,
                            true,
                            $adminId,
                            'Fazil 1st Year enrollment active'
                        );
                    }
                }
            }
        }
    }

    protected function seedEnrollment(
        Student $student,
        AcademicProgram $program,
        ?int $cohortId,
        AcademicPeriod $period,
        AcademicOffering $offering,
        OfferingSection $section,
        AdmissionEntryType $entryType,
        EnrollmentType $enrollmentType,
        EnrollmentStatus $status,
        bool $billingCleared,
        bool $paymentCleared,
        int $actorId,
        string $notes
    ): void {
        $admissionStatus = ($status === EnrollmentStatus::APPROVED)
            ? AdmissionStatus::ACTIVE
            : AdmissionStatus::PENDING_ACTIVATION;

        $admission = ProgramAdmission::updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_program_id' => $program->id,
            ],
            [
                'academic_cohort_id' => $cohortId,
                'entry_type' => $entryType,
                'status' => $admissionStatus,
                'entered_at' => now()->subDays(5),
                'created_by' => $actorId,
            ]
        );

        $enrollment = Enrollment::updateOrCreate(
            [
                'student_id' => $student->id,
                'academic_period_id' => $period->id,
            ],
            [
                'program_admission_id' => $admission->id,
                'academic_offering_id' => $offering->id,
                'offering_section_id' => $section->id,
                'enrollment_type' => $enrollmentType,
                'status' => $status,
                'billing_cleared' => $billingCleared,
                'payment_cleared' => $paymentCleared,
                'submitted_at' => now()->subDays(4),
                'approved_at' => ($status === EnrollmentStatus::APPROVED) ? now()->subDay() : null,
                'created_by' => $actorId,
            ]
        );

        // Status event
        EnrollmentStatusEvent::create([
            'enrollment_id' => $enrollment->id,
            'from_status' => 'DRAFT',
            'to_status' => $status->value,
            'reason' => $notes,
            'actor_id' => $actorId,
            'source_type' => 'USER_ACTION',
            'source_reference' => 'INITIAL_SEED',
            'occurred_at' => now()->subDays(4),
        ]);

        // Outbox event
        EnrollmentOutbox::create([
            'id' => Str::uuid()->toString(),
            'event_type' => 'EnrollmentSubmitted',
            'aggregate_type' => 'Enrollment',
            'aggregate_id' => $enrollment->id,
            'payload_jsonb' => [
                'enrollment_id' => $enrollment->id,
                'student_id' => $student->id,
                'status' => $status->value,
            ],
            'created_at' => now()->subDays(4),
        ]);
    }
}
