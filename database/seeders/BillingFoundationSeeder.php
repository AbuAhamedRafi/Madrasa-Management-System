<?php

namespace Database\Seeders;

use App\Enums\FeeBillingType;
use App\Enums\FeeStructureStatus;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\FeeAdjustmentRequest;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\BillingRunService;
use App\Services\Billing\EnrollmentBillingIntegrationService;
use App\Services\Billing\FeeAdjustmentService;
use App\Services\Billing\FeeStructureService;
use App\Services\Billing\InstallmentPlanService;
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
                'billing_type' => FeeBillingType::ADMISSION,
                'description_bn' => 'নতুন ও পুনঃভর্তির জন্য বাধ্যতামূলক ভর্তি ফি',
                'description_en' => 'Mandatory admission fee for new and returning enrollments',
                'is_active' => true,
                'created_by' => $adminId,
            ],
            [
                'code' => 'SESSION',
                'name_bn' => 'সেশন ও উন্নয়ন ফি',
                'name_en' => 'Session & Development Fee',
                'billing_type' => FeeBillingType::ONE_TIME,
                'description_bn' => 'বার্ষিক সেশন ও ক্যাম্পাস উন্নয়ন ফি',
                'description_en' => 'Annual session and campus development fee',
                'is_active' => true,
                'created_by' => $adminId,
            ],
            [
                'code' => 'TUIT',
                'name_bn' => 'মাসিক টিউশন ফি',
                'name_en' => 'Monthly Tuition Fee',
                'billing_type' => FeeBillingType::RECURRING,
                'description_bn' => 'নিয়মিত মাসিক পাঠদান ফি',
                'description_en' => 'Recurring monthly tuition charge',
                'is_active' => true,
                'created_by' => $adminId,
            ],
            [
                'code' => 'EXAM',
                'name_bn' => 'পরীক্ষা ফি',
                'name_en' => 'Examination Fee',
                'billing_type' => FeeBillingType::EXAM,
                'description_bn' => 'সাময়িক ও বার্ষিক পরীক্ষা ফি',
                'description_en' => 'Term and annual examination fee',
                'is_active' => true,
                'created_by' => $adminId,
            ],
            [
                'code' => 'LIB',
                'name_bn' => 'গ্রন্থাগার ফি',
                'name_en' => 'Library Fee',
                'billing_type' => FeeBillingType::ONE_TIME,
                'description_bn' => 'বার্ষিক গ্রন্থাগার ব্যবহার ফি',
                'description_en' => 'Annual library usage fee',
                'is_active' => true,
                'created_by' => $adminId,
            ],
            [
                'code' => 'LEGACY_ARCH',
                'name_bn' => 'পুরাতন বিবিধ খাত (আর্কাইভ)',
                'name_en' => 'Legacy Misc Charge (Archived)',
                'billing_type' => FeeBillingType::OTHER,
                'description_bn' => 'আর্কাইভকৃত পুরাতন ফি খাত',
                'description_en' => 'Archived legacy miscellaneous charge',
                'is_active' => false,
                'created_by' => $adminId,
            ],
        ];

        $cats = [];
        foreach ($categoriesData as $catRow) {
            $cats[$catRow['code']] = FeeCategory::updateOrCreate(
                ['code' => $catRow['code']],
                $catRow
            );
        }

        // 2. Seed Published Fee Structures across active periods using FeeStructureService
        /** @var FeeStructureService $structureService */
        $structureService = app(FeeStructureService::class);

        $periods = AcademicPeriod::with('program')->get();
        foreach ($periods as $period) {
            $exists = FeeStructure::where('program_id', $period->program_id)
                ->where('academic_period_id', $period->id)
                ->whereNull('program_class_id')
                ->whereNull('academic_offering_id')
                ->where('status', FeeStructureStatus::PUBLISHED)
                ->exists();

            if (!$exists) {
                $draft = $structureService->createDraft([
                    'program_id' => $period->program_id,
                    'academic_period_id' => $period->id,
                    'effective_from' => $period->start_date ? $period->start_date->format('Y-m-d') : '2026-01-01',
                    'effective_to' => $period->end_date ? $period->end_date->format('Y-m-d') : '2027-12-31',
                    'notes' => 'Seeded program-period standard published fee structure',
                    'items' => [
                        [
                            'fee_category_id' => $cats['ADM']->id,
                            'amount' => '1500.00',
                            'cadence' => 'PER_ENROLLMENT',
                            'admission_required' => true,
                        ],
                        [
                            'fee_category_id' => $cats['SESSION']->id,
                            'amount' => '1000.00',
                            'cadence' => 'ANNUAL',
                            'admission_required' => true,
                        ],
                        [
                            'fee_category_id' => $cats['TUIT']->id,
                            'amount' => '500.00',
                            'cadence' => 'MONTHLY',
                            'admission_required' => false,
                        ],
                    ],
                ], $adminId);

                $structureService->publishStructure($draft->id, $adminId);
            }
        }

        // 3. Seed Admission Invoices for existing enrollments using EnrollmentBillingIntegrationService
        /** @var EnrollmentBillingIntegrationService $enrollmentBilling */
        $enrollmentBilling = app(EnrollmentBillingIntegrationService::class);

        $enrollments = Enrollment::with(['offering', 'period'])->take(6)->get();
        foreach ($enrollments as $enr) {
            $hasInvoice = Invoice::where('enrollment_id', $enr->id)->exists();
            if (!$hasInvoice) {
                try {
                    $enrollmentBilling->consumeEnrollmentSubmitted([
                        'event_id' => 'seed-enr-submitted-' . $enr->id,
                        'enrollment_id' => $enr->id,
                        'actor_id' => $adminId,
                    ]);
                } catch (\Throwable $e) {
                    // Ignore if period closed or already processed
                }
            }
        }

        // 4. Seed Installment Plan on the first issued invoice
        /** @var InstallmentPlanService $installmentService */
        $installmentService = app(InstallmentPlanService::class);
        $firstInvoice = Invoice::where('status', 'ISSUED')->orderBy('id')->first();
        if ($firstInvoice && !$firstInvoice->activeInstallmentPlan()->exists()) {
            try {
                $net = (float) $firstInvoice->net_amount;
                $firstHalf = number_format(floor(($net / 2) * 100) / 100, 2, '.', '');
                $secondHalf = number_format($net - (float) $firstHalf, 2, '.', '');

                $installmentService->replacePlan($firstInvoice->id, [
                    'items' => [
                        ['due_date' => '2026-11-05', 'scheduled_amount' => $firstHalf],
                        ['due_date' => '2026-12-05', 'scheduled_amount' => $secondHalf],
                    ],
                ], $adminId);
            } catch (\Throwable $e) {
                // Ignore if already exists
            }
        }

        // 5. Seed Pending & Approved Waiver Requests
        /** @var FeeAdjustmentService $adjustmentService */
        $adjustmentService = app(FeeAdjustmentService::class);
        $issuedInvoices = Invoice::with('items')->where('status', 'ISSUED')->orderBy('id')->take(2)->get();

        if ($issuedInvoices->count() >= 1) {
            $inv1 = $issuedInvoices[0];
            $item1 = $inv1->items->first();
            if ($item1 && !FeeAdjustmentRequest::where('invoice_item_id', $item1->id)->exists()) {
                try {
                    $adjustmentService->requestAdjustment([
                        'invoice_item_id' => $item1->id,
                        'adjustment_type' => 'WAIVER',
                        'requested_amount' => '300.00',
                        'reason' => 'অনাথ ও মেধাবী শিক্ষার্থীর জন্য ভর্তি ফি আংশিক মওকুফ আবেদন',
                    ], $staffId);
                } catch (\Throwable $e) {
                }
            }
        }

        if ($issuedInvoices->count() >= 2) {
            $inv2 = $issuedInvoices[1];
            $item2 = $inv2->items->first();
            if ($item2 && !FeeAdjustmentRequest::where('invoice_item_id', $item2->id)->exists()) {
                try {
                    $req2 = $adjustmentService->requestAdjustment([
                        'invoice_item_id' => $item2->id,
                        'adjustment_type' => 'SCHOLARSHIP',
                        'requested_amount' => '250.00',
                        'reason' => 'বার্ষিক মেধা বৃত্তি ছাড় অনুমোদন',
                    ], $staffId);

                    if ($staffId !== $adminId) {
                        $adjustmentService->approveRequest($req2->id, [
                            'reason' => 'মেধা তালিকা যাচাইপূর্বক অনুমোদিত',
                        ], $adminId);
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        // 6. Seed a Monthly Tuition Billing Run
        /** @var BillingRunService $runService */
        $runService = app(BillingRunService::class);
        $openPeriod = AcademicPeriod::where('state', 'OPEN')->where('calendar_year', 2026)->first();
        if ($openPeriod && \App\Models\BillingRun::count() === 0) {
            try {
                $run = $runService->createRun([
                    'type' => 'MONTHLY_TUITION',
                    'academic_period_id' => $openPeriod->id,
                    'billing_month' => '2026-10',
                    'due_date' => '2026-10-15',
                    'notes' => 'Seeded October 2026 Monthly Tuition Billing Run',
                ], $adminId);

                $runService->executeRun($run->id, $adminId);
            } catch (\Throwable $e) {
            }
        }
    }
}
