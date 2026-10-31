<?php

namespace Database\Seeders;

use App\Enums\AdmissionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use App\Enums\PeriodState;
use App\Enums\PromotionAction;
use App\Enums\PromotionBatchMode;
use App\Enums\PromotionBatchStatus;
use App\Enums\PromotionItemStatus;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Enrollment;
use App\Models\OfferingSection;
use App\Models\ProgramClass;
use App\Models\PromotionAttempt;
use App\Models\PromotionBatch;
use App\Models\PromotionEvent;
use App\Models\PromotionItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PromotionsFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'superadmin')->first()
            ?? User::where('status', 'ACTIVE')->first();
        $adminId = $admin ? $admin->id : 1;

        $program = AcademicProgram::where('code', 'EBT')->first();
        if (!$program) {
            return;
        }

        $sourcePeriod = AcademicPeriod::where('program_id', $program->id)
            ->where('calendar_year', 2026)
            ->first();

        if (!$sourcePeriod) {
            return;
        }

        // Ensure 2027 target period exists
        $targetPeriod = AcademicPeriod::firstOrCreate(
            ['program_id' => $program->id, 'calendar_year' => 2027],
            [
                'cohort_id' => null,
                'label' => 'শিক্ষাবর্ষ ২০২৭ (Ebtedayee)',
                'study_year_number' => null,
                'start_date' => '2027-01-01',
                'end_date' => '2027-12-31',
                'state' => PeriodState::OPEN,
                'lock_version' => 1,
            ]
        );

        // Ensure Class 2 offering and section exist in 2027
        $class2 = ProgramClass::where('program_id', $program->id)->where('stage_number', 2)->first();
        if (!$class2) {
            return;
        }

        $targetOffering = AcademicOffering::firstOrCreate(
            ['academic_period_id' => $targetPeriod->id, 'program_class_id' => $class2->id],
            ['is_active' => true]
        );

        $targetSection = OfferingSection::firstOrCreate(
            ['academic_offering_id' => $targetOffering->id, 'code' => 'EBT-2-A'],
            [
                'name_bn' => 'শাখা ক',
                'name_en' => 'Section A',
                'capacity' => 30,
                'is_active' => true,
            ]
        );

        // Find approved enrollments in 2026 Class 1
        $approvedEnrollments = Enrollment::where('academic_period_id', $sourcePeriod->id)
            ->where('status', EnrollmentStatus::APPROVED->value)
            ->with('student')
            ->get();

        if ($approvedEnrollments->isEmpty()) {
            return;
        }

        // 1. Seed COMPLETED Batch: PRM-2027-0001
        $batch1 = PromotionBatch::create([
            'batch_number' => 'PRM-2027-0001',
            'mode' => PromotionBatchMode::BULK,
            'action' => PromotionAction::PROMOTE,
            'source_academic_period_id' => $sourcePeriod->id,
            'source_offering_section_id' => $approvedEnrollments->first()->offering_section_id,
            'target_academic_period_id' => $targetPeriod->id,
            'target_academic_offering_id' => $targetOffering->id,
            'status' => PromotionBatchStatus::COMPLETED,
            'lock_version' => 1,
            'notes' => 'বার্ষিক প্রমোশন ব্যাচ ২০২৬-২০২৭ (প্রথম ধাপ সম্পন্ন)',
            'created_by' => $adminId,
            'submitted_by' => $adminId,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(1),
        ]);

        $enr1 = $approvedEnrollments->first();
        if ($enr1) {
            // Target enrollment in PENDING_BILLING
            $targetEnr = Enrollment::create([
                'program_admission_id' => $enr1->program_admission_id,
                'student_id' => $enr1->student_id,
                'academic_period_id' => $targetPeriod->id,
                'academic_offering_id' => $targetOffering->id,
                'offering_section_id' => $targetSection->id,
                'enrollment_type' => EnrollmentType::CONTINUING,
                'status' => EnrollmentStatus::PENDING_BILLING,
                'billing_cleared' => false,
                'payment_cleared' => false,
                'lock_version' => 1,
                'submitted_at' => now()->subHours(1),
                'created_by' => $adminId,
            ]);

            $item1 = PromotionItem::create([
                'batch_id' => $batch1->id,
                'student_id' => $enr1->student_id,
                'source_enrollment_id' => $enr1->id,
                'target_offering_section_id' => $targetSection->id,
                'action' => PromotionAction::PROMOTE,
                'academic_eligibility_confirmed' => true,
                'eligibility_confirmed_by' => $adminId,
                'eligibility_confirmed_at' => now()->subHours(2),
                'status' => PromotionItemStatus::SUCCEEDED,
                'target_enrollment_id' => $targetEnr->id,
                'attempt_count' => 1,
            ]);

            PromotionAttempt::create([
                'promotion_item_id' => $item1->id,
                'attempt_number' => 1,
                'idempotency_key' => 'SEED-ATT-' . Str::random(10),
                'request_hash' => hash('sha256', 'seed:item:' . $item1->id),
                'status' => 'SUCCEEDED',
                'started_at' => now()->subHours(2),
                'finished_at' => now()->subHours(1),
                'result_json' => ['target_enrollment_id' => $targetEnr->id],
            ]);

            PromotionEvent::create([
                'promotion_batch_id' => $batch1->id,
                'promotion_item_id' => $item1->id,
                'actor_user_id' => $adminId,
                'action' => 'BATCH_EXECUTION_FINISHED',
                'after_json' => ['status' => 'COMPLETED'],
                'occurred_at' => now()->subHours(1),
            ]);
        }

        // 2. Seed DRAFT Batch: PRM-2027-0002
        $batch2 = PromotionBatch::create([
            'batch_number' => 'PRM-2027-0002',
            'mode' => PromotionBatchMode::BULK,
            'action' => PromotionAction::PROMOTE,
            'source_academic_period_id' => $sourcePeriod->id,
            'source_offering_section_id' => null,
            'target_academic_period_id' => $targetPeriod->id,
            'target_academic_offering_id' => $targetOffering->id,
            'status' => PromotionBatchStatus::DRAFT,
            'lock_version' => 1,
            'notes' => 'খসড়া প্রমোশন ব্যাচ ২০২৭',
            'created_by' => $adminId,
        ]);

        if ($approvedEnrollments->count() > 1) {
            $enr2 = $approvedEnrollments->get(1);
            PromotionItem::create([
                'batch_id' => $batch2->id,
                'student_id' => $enr2->student_id,
                'source_enrollment_id' => $enr2->id,
                'target_offering_section_id' => $targetSection->id,
                'action' => PromotionAction::PROMOTE,
                'academic_eligibility_confirmed' => false,
                'status' => PromotionItemStatus::PENDING,
                'attempt_count' => 0,
            ]);
        }
    }
}

