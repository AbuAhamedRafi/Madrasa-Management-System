<?php

namespace Database\Seeders;

use App\Enums\AdjustmentState;
use App\Enums\AdjustmentType;
use App\Enums\BillingRunItemStatus;
use App\Enums\BillingRunStatus;
use App\Enums\BillingRunType;
use App\Enums\FeeBillingType;
use App\Enums\FeeCadence;
use App\Enums\FeeStructureStatus;
use App\Enums\InstallmentPlanStatus;
use App\Enums\InvoiceSourceType;
use App\Enums\InvoiceStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Enrollment;
use App\Models\FeeAdjustment;
use App\Models\FeeAdjustmentRequest;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ProgramClass;
use App\Models\User;
use Illuminate\Database\Seeder;

class BillingFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'superadmin')->first() ?? User::first();
        $staff = User::where('username', 'staff')->first() ?? $admin;
        $adminId = $admin ? $admin->id : 1;
        $staffId = $staff ? $staff->id : $adminId;

        // 1. Seed Fee Categories idempotently
        $categoriesData = [
            [
                'code' => 'ADM',
                'name_bn' => 'ভর্তি ফি',
                'name_en' => 'Admission Fee',
                'billing_type' => FeeBillingType::ONE_TIME,
                'default_cadence' => FeeCadence::ONCE,
                'is_mandatory_default' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'SESSION',
                'name_bn' => 'সেশন ও উন্নয়ন ফি',
                'name_en' => 'Session & Development Fee',
                'billing_type' => FeeBillingType::ONE_TIME,
                'default_cadence' => FeeCadence::ANNUAL,
                'is_mandatory_default' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'TUIT',
                'name_bn' => 'মাসিক টিউশন ফি',
                'name_en' => 'Monthly Tuition Fee',
                'billing_type' => FeeBillingType::RECURRING,
                'default_cadence' => FeeCadence::MONTHLY,
                'is_mandatory_default' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'code' => 'EXAM',
                'name_bn' => 'পরীক্ষা ফি',
                'name_en' => 'Examination Fee',
                'billing_type' => FeeBillingType::EVENT_BASED,
                'default_cadence' => FeeCadence::TERM,
                'is_mandatory_default' => true,
                'is_active' => true,
                'sort_order' => 40,
            ],
            [
                'code' => 'LIB',
                'name_bn' => 'গ্রন্থাগার ফি',
                'name_en' => 'Library Fee',
                'billing_type' => FeeBillingType::ONE_TIME,
                'default_cadence' => FeeCadence::ANNUAL,
                'is_mandatory_default' => false,
                'is_active' => true,
                'sort_order' => 50,
            ],
            [
                'code' => 'LEGACY_ARCH',
                'name_bn' => 'পুরাতন বিবিধ খাত (আর্কাইভ)',
                'name_en' => 'Legacy Misc Charge (Archived)',
                'billing_type' => FeeBillingType::EVENT_BASED,
                'default_cadence' => FeeCadence::AD_HOC,
                'is_mandatory_default' => false,
                'is_active' => false,
                'sort_order' => 90,
            ],
        ];

        $cats = [];
        foreach ($categoriesData as $catRow) {
            $cats[$catRow['code']] = FeeCategory::updateOrCreate(
                ['code' => $catRow['code']],
                $catRow
            );
        }

        // 2. Seed Published Fee Structures across active periods & classes
        $periods = AcademicPeriod::with('program')->get();
        foreach ($periods as $period) {
            $classes = ProgramClass::where('program_id', $period->program_id)->get();
            foreach ($classes as $pClass) {
                $structCode = sprintf('FS-%s-%d-V1', strtoupper($pClass->code), $period->id);
                $structure = FeeStructure::firstOrCreate(
                    [
                        'program_id' => $period->program_id,
                        'academic_period_id' => $period->id,
                        'program_class_id' => $pClass->id,
                        'version_no' => 1,
                    ],
                    [
                        'code' => $structCode,
                        'name_bn' => $pClass->name_bn . ' — ' . ($period->label ?? 'ফি কাঠামো'),
                        'name_en' => $pClass->name_en . ' — Standard Fee Structure',
                        'status' => FeeStructureStatus::PUBLISHED,
                        'effective_from' => $period->start_date ?? '2026-01-01',
                        'effective_to' => $period->end_date ?? '2027-12-31',
                        'published_at' => now(),
                        'published_by' => $adminId,
                        'lock_version' => 1,
                        'notes' => 'Seeded published fee structure for D06',
                    ]
                );

                if ($structure->items()->count() === 0) {
                    FeeStructureItem::create([
                        'fee_structure_id' => $structure->id,
                        'fee_category_id' => $cats['ADM']->id,
                        'amount' => '1500.00',
                        'cadence' => FeeCadence::ONCE,
                        'is_mandatory' => true,
                        'applies_on_admission' => true,
                        'applies_on_promotion' => false,
                        'applies_monthly' => false,
                        'due_day_of_month' => 15,
                        'sort_order' => 10,
                    ]);
                    FeeStructureItem::create([
                        'fee_structure_id' => $structure->id,
                        'fee_category_id' => $cats['SESSION']->id,
                        'amount' => '1000.00',
                        'cadence' => FeeCadence::ANNUAL,
                        'is_mandatory' => true,
                        'applies_on_admission' => true,
                        'applies_on_promotion' => true,
                        'applies_monthly' => false,
                        'due_day_of_month' => 15,
                        'sort_order' => 20,
                    ]);
                    FeeStructureItem::create([
                        'fee_structure_id' => $structure->id,
                        'fee_category_id' => $cats['TUIT']->id,
                        'amount' => '500.00',
                        'cadence' => FeeCadence::MONTHLY,
                        'is_mandatory' => true,
                        'applies_on_admission' => false,
                        'applies_on_promotion' => false,
                        'applies_monthly' => true,
                        'due_day_of_month' => 15,
                        'sort_order' => 30,
                    ]);
                }
            }
        }

        // 3. Seed Admission Invoices for existing enrollments
        $enrollments = Enrollment::with(['offering', 'period'])->take(8)->get();
        $seq = 1;
        $firstIssuedInvoice = null;
        $secondIssuedInvoice = null;

        foreach ($enrollments as $enr) {
            $classId = $enr->offering?->program_class_id
                ?? ProgramClass::where('program_id', $enr->period?->program_id)->value('id');
            if (!$classId) {
                continue;
            }

            $structure = FeeStructure::where('academic_period_id', $enr->academic_period_id)
                ->where('program_class_id', $classId)
                ->first();

            $sourceRef = 'SEED-ADM-ENR-' . $enr->id;
            $inv = Invoice::where('enrollment_id', $enr->id)
                ->where('source_type', InvoiceSourceType::ADMISSION)
                ->where('source_reference', $sourceRef)
                ->first();

            if (!$inv) {
                $invNo = sprintf('INV-2026-%06d', 9000 + $seq);
                $inv = Invoice::create([
                    'invoice_number' => $invNo,
                    'student_id' => $enr->student_id,
                    'enrollment_id' => $enr->id,
                    'academic_period_id' => $enr->academic_period_id,
                    'program_class_id' => $classId,
                    'offering_section_id' => $enr->offering_section_id,
                    'fee_structure_id' => $structure?->id,
                    'source_type' => InvoiceSourceType::ADMISSION,
                    'source_reference' => $sourceRef,
                    'billing_month' => null,
                    'issue_date' => '2026-01-10',
                    'due_date' => '2026-01-25',
                    'status' => InvoiceStatus::ISSUED,
                    'subtotal_amount' => '2500.00',
                    'adjustment_total_amount' => '0.00',
                    'net_payable_amount' => '2500.00',
                    'currency' => 'BDT',
                    'issued_by' => $adminId,
                    'issued_at' => now(),
                    'lock_version' => 1,
                    'notes' => 'Seeded admission invoice',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $inv->id,
                    'fee_category_id' => $cats['ADM']->id,
                    'description_bn' => 'ভর্তি ফি',
                    'description_en' => 'Admission Fee',
                    'quantity' => 1,
                    'unit_amount' => '1500.00',
                    'gross_amount' => '1500.00',
                    'adjustment_amount' => '0.00',
                    'net_amount' => '1500.00',
                ]);

                InvoiceItem::create([
                    'invoice_id' => $inv->id,
                    'fee_category_id' => $cats['SESSION']->id,
                    'description_bn' => 'সেশন ও উন্নয়ন ফি',
                    'description_en' => 'Session & Development Fee',
                    'quantity' => 1,
                    'unit_amount' => '1000.00',
                    'gross_amount' => '1000.00',
                    'adjustment_amount' => '0.00',
                    'net_amount' => '1000.00',
                ]);
            }

            if ($seq === 1) {
                $firstIssuedInvoice = $inv;
            } elseif ($seq === 2) {
                $secondIssuedInvoice = $inv;
            }
            $seq++;
        }

        // 4. Seed Installment Plan on $firstIssuedInvoice
        if ($firstIssuedInvoice && !$firstIssuedInvoice->activeInstallmentPlan()->exists()) {
            $plan = InstallmentPlan::create([
                'invoice_id' => $firstIssuedInvoice->id,
                'version_no' => 1,
                'status' => InstallmentPlanStatus::ACTIVE,
                'total_scheduled_amount' => $firstIssuedInvoice->net_payable_amount,
                'created_by' => $adminId,
                'approved_by' => $adminId,
            ]);

            InstallmentScheduleItem::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => 1,
                'due_date' => '2026-02-05',
                'amount' => '1500.00',
                'notes' => '1st Installment (Admission)',
            ]);
            InstallmentScheduleItem::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => 2,
                'due_date' => '2026-03-05',
                'amount' => '1000.00',
                'notes' => '2nd Installment (Session)',
            ]);
        }

        // 5. Seed Approved & Pending Adjustment Requests
        if ($firstIssuedInvoice) {
            $firstItem = $firstIssuedInvoice->items()->first();
            if ($firstItem && $firstItem->adjustmentRequests()->count() === 0) {
                FeeAdjustmentRequest::create([
                    'invoice_id' => $firstIssuedInvoice->id,
                    'invoice_item_id' => $firstItem->id,
                    'adjustment_type' => AdjustmentType::WAIVER,
                    'requested_amount' => '500.00',
                    'reason' => 'অনাথ ও মেধাবী শিক্ষার্থীর জন্য ভর্তি ফি আংশিক মওকুফ আবেদন',
                    'state' => AdjustmentState::PENDING_APPROVAL,
                    'requested_by' => $staffId,
                    'lock_version' => 1,
                ]);
            }
        }

        if ($secondIssuedInvoice) {
            $secItem = $secondIssuedInvoice->items()->first();
            if ($secItem && $secItem->adjustmentRequests()->count() === 0) {
                $approvedReq = FeeAdjustmentRequest::create([
                    'invoice_id' => $secondIssuedInvoice->id,
                    'invoice_item_id' => $secItem->id,
                    'adjustment_type' => AdjustmentType::SCHOLARSHIP,
                    'requested_amount' => '300.00',
                    'reason' => 'বার্ষিক মেধা বৃত্তি ছাড় অনুমোদন',
                    'state' => AdjustmentState::APPROVED,
                    'requested_by' => $staffId,
                    'reviewed_by' => $adminId,
                    'reviewed_at' => now(),
                    'review_notes' => 'মেধা তালিকা যাচাইপূর্বক অনুমোদিত',
                    'lock_version' => 2,
                ]);

                FeeAdjustment::create([
                    'adjustment_request_id' => $approvedReq->id,
                    'invoice_id' => $secondIssuedInvoice->id,
                    'invoice_item_id' => $secItem->id,
                    'adjustment_type' => AdjustmentType::SCHOLARSHIP,
                    'applied_amount' => '300.00',
                    'applied_by' => $adminId,
                    'applied_at' => now(),
                ]);

                $secItem->update([
                    'adjustment_amount' => '300.00',
                    'net_amount' => '1200.00',
                ]);
                $secondIssuedInvoice->update([
                    'adjustment_total_amount' => '300.00',
                    'net_payable_amount' => '2200.00',
                ]);
            }
        }

        // 6. Seed Completed Monthly Tuition Billing Run (RUN-2026-0001)
        $firstPeriod = AcademicPeriod::where('calendar_year', 2026)->first();
        if ($firstPeriod && !BillingRun::where('run_code', 'RUN-2026-0001')->exists()) {
            $run = BillingRun::create([
                'run_code' => 'RUN-2026-0001',
                'type' => BillingRunType::MONTHLY_TUITION,
                'academic_period_id' => $firstPeriod->id,
                'program_class_id' => null,
                'offering_section_id' => null,
                'billing_month' => '2026-10-01',
                'due_date' => '2026-10-15',
                'status' => BillingRunStatus::COMPLETED,
                'target_student_count' => 3,
                'processed_count' => 3,
                'success_count' => 3,
                'skipped_count' => 0,
                'failed_count' => 0,
                'total_invoiced_amount' => '1500.00',
                'initiated_by' => $adminId,
                'executed_at' => now(),
                'completed_at' => now(),
                'lock_version' => 2,
                'notes' => 'Seeded October 2026 Monthly Tuition Billing Run',
            ]);

            $periodEnrollments = Enrollment::with('offering')
                ->where('academic_period_id', $firstPeriod->id)
                ->take(3)
                ->get();

            $mSeq = 1;
            foreach ($periodEnrollments as $pEnr) {
                $pClassId = $pEnr->offering?->program_class_id
                    ?? ProgramClass::where('program_id', $firstPeriod->program_id)->value('id');
                if (!$pClassId) {
                    continue;
                }

                $mInv = Invoice::create([
                    'invoice_number' => sprintf('INV-2026-%06d', 9500 + $mSeq),
                    'student_id' => $pEnr->student_id,
                    'enrollment_id' => $pEnr->id,
                    'academic_period_id' => $firstPeriod->id,
                    'program_class_id' => $pClassId,
                    'offering_section_id' => $pEnr->offering_section_id,
                    'fee_structure_id' => null,
                    'source_type' => InvoiceSourceType::MONTHLY_RUN,
                    'source_reference' => 'RUN:RUN-2026-0001:2026-10',
                    'billing_month' => '2026-10-01',
                    'issue_date' => '2026-10-01',
                    'due_date' => '2026-10-15',
                    'status' => InvoiceStatus::ISSUED,
                    'subtotal_amount' => '500.00',
                    'adjustment_total_amount' => '0.00',
                    'net_payable_amount' => '500.00',
                    'currency' => 'BDT',
                    'issued_by' => $adminId,
                    'issued_at' => now(),
                    'lock_version' => 1,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $mInv->id,
                    'fee_category_id' => $cats['TUIT']->id,
                    'description_bn' => 'মাসিক টিউশন ফি (2026-10)',
                    'description_en' => 'Monthly Tuition Fee (2026-10)',
                    'quantity' => 1,
                    'unit_amount' => '500.00',
                    'gross_amount' => '500.00',
                    'adjustment_amount' => '0.00',
                    'net_amount' => '500.00',
                ]);

                BillingRunItem::create([
                    'billing_run_id' => $run->id,
                    'student_id' => $pEnr->student_id,
                    'enrollment_id' => $pEnr->id,
                    'invoice_id' => $mInv->id,
                    'status' => BillingRunItemStatus::ISSUED,
                    'expected_amount' => '500.00',
                    'processed_at' => now(),
                ]);

                $mSeq++;
            }
        }
    }
}

