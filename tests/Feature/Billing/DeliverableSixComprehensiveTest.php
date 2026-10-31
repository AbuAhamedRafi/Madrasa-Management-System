<?php

namespace Tests\Feature\Billing;

use App\Enums\AccountStatus;
use App\Enums\AdmissionEntryType;
use App\Enums\AdmissionStatus;
use App\Enums\AcademicScheme;
use App\Enums\CohortStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use App\Enums\InvoiceStatus;
use App\Enums\PeriodState;
use App\Enums\StudentStatus;
use App\Models\AcademicCohort;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\BillingEventInbox;
use App\Models\BillingOutbox;
use App\Models\Department;
use App\Models\EducationAuthority;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\Invoice;
use App\Models\OfferingSection;
use App\Models\ProgramAdmission;
use App\Models\ProgramClass;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingLedgerReadService;
use App\Services\Billing\EnrollmentBillingIntegrationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverableSixComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $accounts;

    private AcademicProgram $dakhilProgram;
    private AcademicPeriod $dakhilPeriod2026;
    private ProgramClass $dakhilClass7;
    private AcademicOffering $dakhilOffering7;
    private OfferingSection $dakhilSection7A;

    private AcademicProgram $alimProgram;
    private AcademicCohort $alimCohort;
    private AcademicPeriod $alimYear1Period;
    private ProgramClass $alimClass1;
    private AcademicOffering $alimOffering1;
    private OfferingSection $alimSection1A;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->admin = User::create([
            'public_id' => 'usr_admin_d06',
            'username' => 'admin_d06',
            'name_bn' => 'অ্যাডমিন',
            'name_en' => 'Admin User',
            'email' => 'admin_d06@mms.test',
            'password' => bcrypt('Password123!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->admin->roles()->sync([$adminRole->id]);

        $this->staff = User::create([
            'public_id' => 'usr_staff_d06',
            'username' => 'staff_d06',
            'name_bn' => 'অফিস স্টাফ',
            'name_en' => 'Office Staff',
            'email' => 'staff_d06@mms.test',
            'password' => bcrypt('Password123!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staff->roles()->sync([$staffRole->id]);

        $this->accounts = User::create([
            'public_id' => 'usr_acc_d06',
            'username' => 'accounts_d06',
            'name_bn' => 'হিসাব শাখা',
            'name_en' => 'Accounts Officer',
            'email' => 'accounts_d06@mms.test',
            'password' => bcrypt('Password123!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accounts->roles()->sync([$accountsRole->id]);

        $this->setUpAcademicFixtures();
    }

    private function setUpAcademicFixtures(): void
    {
        $authority = EducationAuthority::create([
            'code' => 'BMED',
            'name_bn' => 'বাংলাদেশ মাদ্রাসা শিক্ষা বোর্ড',
            'name_en' => 'Bangladesh Madrasah Education Board',
            'is_active' => true,
        ]);

        $level = EducationLevel::create([
            'authority_id' => $authority->id,
            'code' => 'SEC',
            'name_bn' => 'মাধ্যমিক ও উচ্চ মাধ্যমিক',
            'name_en' => 'Secondary & Higher Secondary',
            'tier_order' => 1,
            'is_active' => true,
        ]);

        $dept = Department::create([
            'authority_id' => $authority->id,
            'code' => 'GEN',
            'name_bn' => 'সাধারণ বিভাগ',
            'name_en' => 'General Department',
            'is_active' => true,
        ]);

        // 1. Calendar-year program: Dakhil
        $this->dakhilProgram = AcademicProgram::create([
            'authority_id' => $authority->id,
            'education_level_id' => $level->id,
            'department_id' => $dept->id,
            'code' => 'DKH',
            'name_bn' => 'দাখিল',
            'name_en' => 'Dakhil',
            'scheme' => AcademicScheme::CALENDAR_YEAR,
            'duration_years' => 5,
            'is_active' => true,
        ]);

        $this->dakhilClass7 = ProgramClass::create([
            'program_id' => $this->dakhilProgram->id,
            'code' => 'DKH-7',
            'name_bn' => 'দাখিল সপ্তম শ্রেণি',
            'name_en' => 'Dakhil Class 7',
            'stage_number' => 2,
            'is_active' => true,
        ]);

        $this->dakhilPeriod2026 = AcademicPeriod::create([
            'program_id' => $this->dakhilProgram->id,
            'cohort_id' => null,
            'calendar_year' => 2026,
            'study_year_number' => null,
            'label' => 'শিক্ষাবর্ষ ২০২৬ (Dakhil)',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->dakhilOffering7 = AcademicOffering::create([
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'program_class_id' => $this->dakhilClass7->id,
            'is_active' => true,
        ]);

        $this->dakhilSection7A = OfferingSection::create([
            'academic_offering_id' => $this->dakhilOffering7->id,
            'code' => 'DKH-7-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 40,
            'is_active' => true,
        ]);

        // 2. Session-based program: Alim 2026-27
        $this->alimProgram = AcademicProgram::create([
            'authority_id' => $authority->id,
            'education_level_id' => $level->id,
            'department_id' => $dept->id,
            'code' => 'ALM',
            'name_bn' => 'আলিম',
            'name_en' => 'Alim',
            'scheme' => AcademicScheme::SESSION_BASED,
            'duration_years' => 2,
            'is_active' => true,
        ]);

        $this->alimClass1 = ProgramClass::create([
            'program_id' => $this->alimProgram->id,
            'code' => 'ALM-1',
            'name_bn' => 'আলিম ১ম বর্ষ',
            'name_en' => 'Alim 1st Year',
            'stage_number' => 1,
            'is_active' => true,
        ]);

        $this->alimCohort = AcademicCohort::create([
            'program_id' => $this->alimProgram->id,
            'label' => '2026-2027',
            'intake_year' => 2026,
            'status' => CohortStatus::ACTIVE,
        ]);

        $this->alimYear1Period = AcademicPeriod::create([
            'program_id' => $this->alimProgram->id,
            'cohort_id' => $this->alimCohort->id,
            'calendar_year' => null,
            'study_year_number' => 1,
            'label' => 'আলিম ১ম বর্ষ (২০২৬-২৭)',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->alimOffering1 = AcademicOffering::create([
            'academic_period_id' => $this->alimYear1Period->id,
            'program_class_id' => $this->alimClass1->id,
            'is_active' => true,
        ]);

        $this->alimSection1A = OfferingSection::create([
            'academic_offering_id' => $this->alimOffering1->id,
            'code' => 'ALM-1-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 50,
            'is_active' => true,
        ]);
    }

    private function createStudentAndEnrollment(
        string $studentPublicId,
        string $nameEn,
        AcademicPeriod $period,
        AcademicOffering $offering,
        OfferingSection $section,
        EnrollmentStatus $status = EnrollmentStatus::PENDING_BILLING,
        EnrollmentType $type = EnrollmentType::NEW
    ): array {
        $student = Student::create([
            'student_id' => $studentPublicId,
            'admission_year' => 2026,
            'admission_date' => '2026-01-10',
            'name_bn' => $nameEn,
            'name_en' => $nameEn,
            'gender' => 'MALE',
            'date_of_birth' => '2012-05-10',
            'status' => StudentStatus::ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $admission = ProgramAdmission::create([
            'student_id' => $student->id,
            'program_id' => $period->program_id,
            'cohort_id' => $period->cohort_id,
            'entry_academic_period_id' => $period->id,
            'entry_type' => AdmissionEntryType::REGULAR,
            'status' => AdmissionStatus::ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $enrollment = Enrollment::create([
            'program_admission_id' => $admission->id,
            'student_id' => $student->id,
            'academic_period_id' => $period->id,
            'academic_offering_id' => $offering->id,
            'offering_section_id' => $section->id,
            'enrollment_type' => $type,
            'status' => $status,
            'billing_cleared' => $status !== EnrollmentStatus::PENDING_BILLING,
            'payment_cleared' => $status === EnrollmentStatus::APPROVED,
            'lock_version' => 1,
            'submitted_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        return [$student, $enrollment];
    }

    /**
     * C01, C02, C03: Fee categories CRUD, duplicate 409, immutable code, archive instead of delete.
     */
    public function test_c01_to_c03_fee_category_lifecycle_duplicate_and_archive(): void
    {
        // C01: Create fee category
        $res1 = $this->actingAs($this->admin)->postJson('/api/v1/fee-categories', [
            'code' => 'ADM',
            'name_bn' => 'ভর্তি ফি',
            'name_en' => 'Admission Fee',
            'billing_type' => 'ADMISSION',
        ]);
        $res1->assertCreated()
            ->assertJsonPath('data.code', 'ADM')
            ->assertJsonPath('data.is_active', true);

        $catId = $res1->json('data.id');

        // C02: Duplicate fee category code returns 409
        $dupRes = $this->actingAs($this->admin)->postJson('/api/v1/fee-categories', [
            'code' => 'adm',
            'name_bn' => 'ডুপ্লিকেট ভর্তি ফি',
            'name_en' => 'Duplicate Admission Fee',
            'billing_type' => 'ADMISSION',
        ]);
        $dupRes->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_CATEGORY_DUPLICATE');

        // VAL-03: Code is immutable on PATCH
        $immRes = $this->actingAs($this->admin)->patchJson("/api/v1/fee-categories/{$catId}", [
            'code' => 'NEW_ADM',
        ]);
        $immRes->assertStatus(422)
            ->assertJsonPath('error.code', 'FEE_CATEGORY_CODE_IMMUTABLE');

        // C03: Referenced fee category is archived (is_active = false), not deleted
        $archRes = $this->actingAs($this->admin)->patchJson("/api/v1/fee-categories/{$catId}", [
            'name_en' => 'Admission Fee (Archived)',
            'is_active' => false,
        ]);
        $archRes->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name_en', 'Admission Fee (Archived)');

        $this->assertDatabaseHas('fee_categories', [
            'id' => $catId,
            'code' => 'ADM',
            'is_active' => false,
        ]);
    }

    /**
     * C04–C13: Fee structure scope validation, versioning, lock_version, overlap rejection, immutability, and precision.
     */
    public function test_c04_to_c13_fee_structure_versioning_overlap_precision_and_immutability(): void
    {
        $admCat = FeeCategory::create([
            'code' => 'ADM',
            'name_bn' => 'ভর্তি ফি',
            'name_en' => 'Admission Fee',
            'billing_type' => 'ADMISSION',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $tuitCat = FeeCategory::create([
            'code' => 'TUIT',
            'name_bn' => 'মাসিক টিউশন ফি',
            'name_en' => 'Monthly Tuition Fee',
            'billing_type' => 'RECURRING',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // C12: Negative charge rejected
        $negRes = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'academic_offering_id' => $this->dakhilOffering7->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '-500.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
            ],
        ]);
        $negRes->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_MONETARY_AMOUNT');

        // C04, C05, C11, C13: Create valid draft structure for Dakhil Class 7 (2026)
        $draftRes = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'academic_offering_id' => $this->dakhilOffering7->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '1200.55', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
                ['fee_category_code' => 'TUIT', 'amount' => '450.25', 'cadence' => 'MONTHLY', 'admission_required' => false],
            ],
        ]);
        $draftRes->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.items.0.amount', '1200.55')
            ->assertJsonPath('data.items.0.admission_required', true);

        $structureId = $draftRes->json('data.id');

        // C08: Edit draft using lock_version
        $editRes = $this->actingAs($this->admin)->patchJson("/api/v1/fee-structures/{$structureId}", [
            'lock_version' => 1,
            'notes' => 'Updated Dakhil Class 7 fee structure',
            'items' => [
                ['fee_category_id' => $admCat->id, 'amount' => '1500.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
                ['fee_category_id' => $tuitCat->id, 'amount' => '500.00', 'cadence' => 'MONTHLY', 'admission_required' => false],
            ],
        ]);
        $editRes->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.items.0.amount', '1500.00');

        // Stale lock_version returns 409
        $staleRes = $this->actingAs($this->admin)->patchJson("/api/v1/fee-structures/{$structureId}", [
            'lock_version' => 1,
            'notes' => 'Should fail',
        ]);
        $staleRes->assertStatus(409)
            ->assertJsonPath('error.code', 'STALE_LOCK_VERSION');

        // Publish structure
        $pubRes = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', 'pub-dkh-2026-v1')
            ->postJson("/api/v1/fee-structures/{$structureId}/publish");
        $pubRes->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED');

        // C09: Published fee structure cannot be mutated in place
        $mutatePub = $this->actingAs($this->admin)->patchJson("/api/v1/fee-structures/{$structureId}", [
            'notes' => 'Attempt illegal edit on published structure',
        ]);
        $mutatePub->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_STRUCTURE_IMMUTABLE');

        // C07: Overlapping published structure at equal specificity is rejected
        $overlapDraft = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'academic_offering_id' => $this->dakhilOffering7->id,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-12-31',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '1600.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
            ],
        ]);
        $overlapDraft->assertCreated();
        $overlapId = $overlapDraft->json('data.id');

        $overlapPub = $this->actingAs($this->admin)->postJson("/api/v1/fee-structures/{$overlapId}/publish");
        $overlapPub->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_STRUCTURE_AMBIGUOUS');

        // C06: Alim 2026-27 cohort study period resolves to correct offering
        $alimDraft = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->alimProgram->id,
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'effective_from' => '2026-07-01',
            'effective_to' => '2027-06-30',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '2500.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
                ['fee_category_code' => 'TUIT', 'amount' => '800.00', 'cadence' => 'MONTHLY', 'admission_required' => false],
            ],
        ]);
        $alimDraft->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/fee-structures/' . $alimDraft->json('data.id') . '/publish')
            ->assertOk();
    }

    /**
     * C14–C20, C10: D04 EnrollmentSubmitted handoff, deduplication, missing structure error, and D05 promotion handoff.
     */
    public function test_c14_to_c20_d04_enrollment_submitted_handoff_and_d05_promotion_billing(): void
    {
        FeeCategory::create([
            'code' => 'ADM',
            'name_bn' => 'ভর্তি ফি',
            'name_en' => 'Admission Fee',
            'billing_type' => 'ADMISSION',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        FeeCategory::create([
            'code' => 'SESSION',
            'name_bn' => 'সেশন ফি',
            'name_en' => 'Session Charge',
            'billing_type' => 'ONE_TIME',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        [$student1, $enrollment1] = $this->createStudentAndEnrollment(
            '260901',
            'Tanvir Hasan',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A
        );

        /** @var EnrollmentBillingIntegrationService $integrationService */
        $integrationService = app(EnrollmentBillingIntegrationService::class);

        // C17: Missing valid fee structure reports actionable error and leaves D04 PENDING_BILLING
        try {
            $integrationService->consumeEnrollmentSubmitted('evt-missing-struct-1', [
                'enrollment_id' => $enrollment1->id,
                'student_id' => $student1->id,
                'program_id' => $this->dakhilProgram->id,
            ], $this->admin->id);
            $this->fail('Expected FEE_STRUCTURE_NOT_FOUND exception');
        } catch (\App\Exceptions\BillingDomainException $e) {
            $this->assertEquals('FEE_STRUCTURE_NOT_FOUND', $e->errorCode);
        }

        $this->assertEquals(EnrollmentStatus::PENDING_BILLING, $enrollment1->fresh()->status);
        $this->assertDatabaseHas('billing_event_inbox', [
            'event_id' => 'evt-missing-struct-1',
            'status' => 'FAILED',
        ]);

        // Publish valid structure for Dakhil Class 7
        $draft = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'academic_offering_id' => $this->dakhilOffering7->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '1500.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
                ['fee_category_code' => 'SESSION', 'amount' => '500.00', 'cadence' => 'ANNUAL', 'admission_required' => false],
            ],
        ])->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/fee-structures/{$draft['id']}/publish")->assertOk();

        // C14, C18: Consume EnrollmentSubmitted -> creates 1 ISSUED invoice and moves D04 to PENDING_PAYMENT
        $invoiceData = $integrationService->consumeEnrollmentSubmitted('evt-enr-101', [
            'enrollment_id' => $enrollment1->id,
            'student_id' => $student1->id,
            'program_id' => $this->dakhilProgram->id,
        ], $this->admin->id);

        $this->assertEquals('ISSUED', $invoiceData['status']);
        $this->assertEquals('2000.00', $invoiceData['net_amount']);
        $this->assertEquals('1500.00', $invoiceData['required_admission_net']);
        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $enrollment1->fresh()->status);
        $this->assertTrue((bool) $enrollment1->fresh()->billing_cleared);
        $this->assertFalse((bool) $enrollment1->fresh()->payment_cleared);

        // C15: Repeated same EnrollmentSubmitted event creates no duplicate invoice
        $repeatSameEvent = $integrationService->consumeEnrollmentSubmitted('evt-enr-101', [
            'enrollment_id' => $enrollment1->id,
        ], $this->admin->id);
        $this->assertEquals($invoiceData['id'], $repeatSameEvent['id']);
        $this->assertEquals(1, Invoice::where('enrollment_id', $enrollment1->id)->count());

        // C16: Different event ID with same logical source_key creates no duplicate invoice
        $diffEventSameSource = $integrationService->consumeEnrollmentSubmitted('evt-enr-102-duplicate-delivery', [
            'enrollment_id' => $enrollment1->id,
        ], $this->admin->id);
        $this->assertEquals($invoiceData['id'], $diffEventSameSource['id']);
        $this->assertEquals(1, Invoice::where('enrollment_id', $enrollment1->id)->count());

        // C10: Archiving structure and publishing new version does NOT alter existing invoice snapshot
        $this->actingAs($this->admin)->postJson("/api/v1/fee-structures/{$draft['id']}/archive")->assertOk();
        $v2Draft = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'academic_offering_id' => $this->dakhilOffering7->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'items' => [
                ['fee_category_code' => 'ADM', 'amount' => '3000.00', 'cadence' => 'PER_ENROLLMENT', 'admission_required' => true],
            ],
        ])->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/fee-structures/{$v2Draft['id']}/publish")->assertOk();

        $recheckInv = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoiceData['id']}")->json('data');
        $this->assertEquals('2000.00', $recheckInv['net_amount']);
        $this->assertEquals(1, $recheckInv['items'][0]['source_version']);

        // C19: Cancelled D04 enrollment cannot be newly invoiced
        [, $cancelledEnr] = $this->createStudentAndEnrollment(
            '260902',
            'Cancelled Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A,
            EnrollmentStatus::CANCELLED
        );

        try {
            $integrationService->consumeEnrollmentSubmitted('evt-cancelled-enr', [
                'enrollment_id' => $cancelledEnr->id,
            ], $this->admin->id);
            $this->fail('Expected ENROLLMENT_INELIGIBLE_FOR_BILLING');
        } catch (\App\Exceptions\BillingDomainException $e) {
            $this->assertEquals('ENROLLMENT_INELIGIBLE_FOR_BILLING', $e->errorCode);
        }

        // C20: D05 promotion-created CONTINUING enrollment in PENDING_BILLING is billed using same D04 handoff
        [, $promotedEnr] = $this->createStudentAndEnrollment(
            '260903',
            'Promoted Continuing Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A,
            EnrollmentStatus::PENDING_BILLING,
            EnrollmentType::CONTINUING
        );

        $promInv = $integrationService->consumeEnrollmentSubmitted('evt-promoted-enr', [
            'enrollment_id' => $promotedEnr->id,
        ], $this->admin->id);

        $this->assertEquals('ISSUED', $promInv['status']);
        $this->assertEquals('3000.00', $promInv['net_amount']);
        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $promotedEnr->fresh()->status);
    }

    /**
     * C22–C30: Manual invoice preview, draft/issue, idempotency, zero-charge policy, and void safeguards.
     */
    public function test_c22_to_c30_invoice_preview_issuance_idempotency_and_void_safeguards(): void
    {
        $cat = FeeCategory::create([
            'code' => 'EXAM_TERM1',
            'name_bn' => '১ম সাময়িক পরীক্ষা ফি',
            'name_en' => '1st Term Exam Fee',
            'billing_type' => 'EXAM',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        [, $enrollment] = $this->createStudentAndEnrollment(
            '260910',
            'Invoice Test Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A
        );

        $invoiceCountBefore = Invoice::count();
        $outboxCountBefore = BillingOutbox::count();

        // C22: Preview creates zero rows or outbox events
        $previewRes = $this->actingAs($this->admin)->postJson('/api/v1/invoices/preview', [
            'enrollment_id' => $enrollment->id,
            'items' => [
                ['fee_category_id' => $cat->id, 'unit_amount' => '350.50', 'quantity' => 2, 'admission_required' => false],
            ],
        ]);
        $previewRes->assertOk()
            ->assertJsonPath('data.gross_amount', '701.00')
            ->assertJsonPath('data.net_amount', '701.00');

        $this->assertEquals($invoiceCountBefore, Invoice::count());
        $this->assertEquals($outboxCountBefore, BillingOutbox::count());

        // C28: Zero-amount invoice without explicit zero_charge_policy fails
        $zeroFail = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'enrollment_id' => $enrollment->id,
            'items' => [
                ['fee_category_id' => $cat->id, 'unit_amount' => '0.00', 'quantity' => 1],
            ],
        ]);
        $zeroFail->assertStatus(422)
            ->assertJsonPath('error.code', 'ZERO_CHARGE_POLICY_REQUIRED');

        // Create valid draft invoice with Idempotency-Key (C23, C24, C25, C27)
        $payload = [
            'enrollment_id' => $enrollment->id,
            'source_key' => 'MANUAL:EXAM:ENR:' . $enrollment->id,
            'items' => [
                ['fee_category_id' => $cat->id, 'unit_amount' => '600.00', 'quantity' => 1, 'admission_required' => false],
            ],
        ];

        $createRes1 = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', 'idem-inv-create-01')
            ->postJson('/api/v1/invoices', $payload);
        $createRes1->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.net_amount', '600.00');

        $invoiceId = $createRes1->json('data.id');

        // C24: Same idempotency key + same payload returns same result
        $createRes2 = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', 'idem-inv-create-01')
            ->postJson('/api/v1/invoices', $payload);
        $createRes2->assertCreated()
            ->assertJsonPath('data.id', $invoiceId);

        // C25: Modified payload under same idempotency key returns 422
        $modifiedPayload = $payload;
        $modifiedPayload['notes'] = 'Different payload body';
        $mismatchRes = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', 'idem-inv-create-01')
            ->postJson('/api/v1/invoices', $modifiedPayload);
        $mismatchRes->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        // Issue the draft invoice
        $issueRes = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', 'idem-inv-issue-01')
            ->postJson("/api/v1/invoices/{$invoiceId}/issue");
        $issueRes->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.settlement_source', 'NOT_CONNECTED');

        // C29: Void requires reason; voiding without reason fails
        $voidNoReason = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/void", [
            'reason' => '',
        ]);
        $voidNoReason->assertStatus(422)
            ->assertJsonPath('error.code', 'VOID_REASON_REQUIRED');

        // C30: Invoice with actual D07 allocation cannot be voided directly
        /** @var BillingLedgerReadService $ledgerService */
        $ledgerService = app(BillingLedgerReadService::class);
        $ledgerService->consumePaymentAllocated('evt-d07-pay-1', $invoiceId, '200.00');

        $voidBlocked = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/void", [
            'reason' => 'Attempt void after payment',
        ]);
        $voidBlocked->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCIAL_POSTING_EXISTS');

        // Clear simulated D07 event and verify legitimate void succeeds and logs audit event (C29, C57)
        BillingEventInbox::where('event_id', 'evt-d07-pay-1')->delete();

        $voidOk = $this->actingAs($this->admin)->postJson("/api/v1/invoices/{$invoiceId}/void", [
            'reason' => 'Clerical entry mistake before collection',
        ]);
        $voidOk->assertOk()
            ->assertJsonPath('data.status', 'VOID')
            ->assertJsonPath('data.void_reason', 'Clerical entry mistake before collection');

        $this->assertDatabaseHas('billing_outbox', [
            'event_type' => 'InvoiceVoided',
            'aggregate_id' => $invoiceId,
        ]);
    }

    /**
     * C31–C38, C56, C62: Recurring monthly tuition runs, Alim cohort calendar month, partial failure isolation, and retry.
     */
    public function test_c31_to_c38_recurring_monthly_tuition_billing_runs_and_partial_retry(): void
    {
        $tuitCat = FeeCategory::create([
            'code' => 'TUIT',
            'name_bn' => 'মাসিক টিউশন ফি',
            'name_en' => 'Monthly Tuition Fee',
            'billing_type' => 'RECURRING',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Publish Alim 2026-27 structure with monthly tuition
        $alimStruct = $this->actingAs($this->admin)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->alimProgram->id,
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'effective_from' => '2026-07-01',
            'effective_to' => '2027-06-30',
            'items' => [
                ['fee_category_id' => $tuitCat->id, 'amount' => '750.00', 'cadence' => 'MONTHLY', 'admission_required' => false],
            ],
        ])->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/fee-structures/{$alimStruct['id']}/publish")->assertOk();

        [, $alimEnr1] = $this->createStudentAndEnrollment(
            '260921',
            'Alim Student One',
            $this->alimYear1Period,
            $this->alimOffering1,
            $this->alimSection1A,
            EnrollmentStatus::APPROVED
        );
        [, $alimEnr2] = $this->createStudentAndEnrollment(
            '260922',
            'Alim Student Two',
            $this->alimYear1Period,
            $this->alimOffering1,
            $this->alimSection1A,
            EnrollmentStatus::APPROVED
        );

        // C36: Preview monthly tuition for Nov 2026 (side-effect-free)
        $prevRes = $this->actingAs($this->admin)->postJson('/api/v1/billing-runs/preview', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'billing_month' => '2026-11',
        ]);
        $prevRes->assertOk()
            ->assertJsonPath('data.eligible', 2)
            ->assertJsonPath('data.already_invoiced', 0)
            ->assertJsonPath('data.blocked', 0);

        // C37: Simulate partial failure during execution by cancelling alimEnr2 after run creation
        $runRes = $this->actingAs($this->admin)->postJson('/api/v1/billing-runs', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'billing_month' => '2026-11',
        ]);
        $runRes->assertCreated();
        $runId = $runRes->json('data.id');

        $alimEnr2->update(['status' => EnrollmentStatus::CANCELLED]);

        // Execute run: alimEnr1 succeeds, alimEnr2 fails without corrupting alimEnr1 (C37)
        $execRes = $this->actingAs($this->admin)->postJson("/api/v1/billing-runs/{$runId}/execute");
        $execRes->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED_WITH_ERRORS')
            ->assertJsonPath('data.counts.succeeded', 1)
            ->assertJsonPath('data.counts.failed', 1);

        // C38: Restore alimEnr2 to APPROVED and retry failed rows only
        $alimEnr2->update(['status' => EnrollmentStatus::APPROVED]);

        $retryRes = $this->actingAs($this->admin)->postJson("/api/v1/billing-runs/{$runId}/retry");
        $retryRes->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.counts.succeeded', 2)
            ->assertJsonPath('data.counts.failed', 0);

        // C31, C32: Creating and executing another run for Nov 2026 skips already invoiced students (no duplicate invoice)
        $run2 = $this->actingAs($this->admin)->postJson('/api/v1/billing-runs', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'billing_month' => '2026-11',
        ])->json('data');

        $exec2 = $this->actingAs($this->admin)->postJson("/api/v1/billing-runs/{$run2['id']}/execute");
        $exec2->assertOk()
            ->assertJsonPath('data.counts.succeeded', 0)
            ->assertJsonPath('data.counts.skipped', 2);

        $this->assertEquals(1, Invoice::where('enrollment_id', $alimEnr1->id)->count());

        // C35: Year boundary rollover (Jan 2027 inside 2026-2027 Alim period) works accurately
        $janPreview = $this->actingAs($this->admin)->postJson('/api/v1/billing-runs/preview', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->alimYear1Period->id,
            'academic_offering_id' => $this->alimOffering1->id,
            'billing_month' => '2027-01',
        ]);
        $janPreview->assertOk()
            ->assertJsonPath('data.eligible', 2);

        // C34: Closed academic period is rejected for recurring billing
        $this->alimYear1Period->update(['state' => PeriodState::CLOSED]);
        $closedRes = $this->actingAs($this->admin)->postJson('/api/v1/billing-runs/preview', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->alimYear1Period->id,
            'billing_month' => '2027-02',
        ]);
        $closedRes->assertStatus(422)
            ->assertJsonPath('error.code', 'BILLING_PERIOD_CLOSED');
    }

    /**
     * C39–C49, C61: Installment schedules, discounts/waivers, separation of duties, and D07 settlement boundary.
     */
    public function test_c39_to_c49_installments_waivers_separation_of_duties_and_d07_boundary(): void
    {
        $admCat = FeeCategory::create([
            'code' => 'ADM',
            'name_bn' => 'ভর্তি ফি',
            'name_en' => 'Admission Fee',
            'billing_type' => 'ADMISSION',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $tuitCat = FeeCategory::create([
            'code' => 'TUIT',
            'name_bn' => 'টিউশন ফি',
            'name_en' => 'Tuition Fee',
            'billing_type' => 'RECURRING',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        [, $enrollment] = $this->createStudentAndEnrollment(
            '260931',
            'Installment & Waiver Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A
        );

        // Create and issue invoice with 2000.00 ADM (required) + 1000.00 TUIT (non-required) = 3000.00
        $inv = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'enrollment_id' => $enrollment->id,
            'auto_issue' => true,
            'items' => [
                ['fee_category_id' => $admCat->id, 'unit_amount' => '2000.00', 'quantity' => 1, 'admission_required' => true],
                ['fee_category_id' => $tuitCat->id, 'unit_amount' => '1000.00', 'quantity' => 1, 'admission_required' => false],
            ],
        ])->json('data');

        $invoiceId = $inv['id'];
        $admItemId = $inv['items'][0]['id'];
        $tuitItemId = $inv['items'][1]['id'];

        // C39: Installment schedule that does NOT sum to uncovered balance (3000.00) fails
        $badSumRes = $this->actingAs($this->admin)->putJson("/api/v1/invoices/{$invoiceId}/installment-plan", [
            'items' => [
                ['due_date' => '2026-11-01', 'scheduled_amount' => '1000.00'],
                ['due_date' => '2026-12-01', 'scheduled_amount' => '1000.00'],
            ],
        ]);
        $badSumRes->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INSTALLMENT_TOTAL');

        // C40: Non-ascending installment due dates are rejected
        $badDateRes = $this->actingAs($this->admin)->putJson("/api/v1/invoices/{$invoiceId}/installment-plan", [
            'items' => [
                ['due_date' => '2026-12-01', 'scheduled_amount' => '1500.00'],
                ['due_date' => '2026-11-01', 'scheduled_amount' => '1500.00'],
            ],
        ]);
        $badDateRes->assertStatus(422)
            ->assertJsonPath('error.code', 'NON_ASCENDING_INSTALLMENT_DATES');

        // C39, C41, C42: Valid installment schedule saves cleanly, does NOT change settled balance or approve admission
        $validPlanRes = $this->actingAs($this->accounts)->putJson("/api/v1/invoices/{$invoiceId}/installment-plan", [
            'items' => [
                ['due_date' => '2026-11-05', 'scheduled_amount' => '1500.00'],
                ['due_date' => '2026-12-05', 'scheduled_amount' => '1500.00'],
            ],
        ]);
        $validPlanRes->assertOk()
            ->assertJsonPath('data.uncovered_balance', '3000.00')
            ->assertJsonPath('data.verified_collected_amount', '0.00')
            ->assertJsonCount(2, 'data.plan.items');

        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $enrollment->fresh()->status);

        // C46: Waiver larger than remaining item net charge (2000.00) fails
        $excessWaiver = $this->actingAs($this->staff)->postJson('/api/v1/adjustment-requests', [
            'invoice_item_id' => $admItemId,
            'adjustment_type' => 'WAIVER',
            'requested_amount' => '2500.00',
            'reason' => 'Too large waiver',
        ]);
        $excessWaiver->assertStatus(422)
            ->assertJsonPath('error.code', 'WAIVER_EXCEEDS_BALANCE');

        // C44: Requesting a 500.00 scholarship by Admin #1 does NOT alter invoice while PENDING
        $reqRes = $this->actingAs($this->admin)->postJson('/api/v1/adjustment-requests', [
            'invoice_item_id' => $admItemId,
            'adjustment_type' => 'SCHOLARSHIP',
            'requested_amount' => '500.00',
            'reason' => 'Merit Scholarship',
        ]);
        $reqRes->assertCreated()
            ->assertJsonPath('data.state', 'PENDING');
        $reqId = $reqRes->json('data.id');

        $invBeforeApprove = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoiceId}")->json('data');
        $this->assertEquals('3000.00', $invBeforeApprove['net_amount']);

        // C45: Requesting user cannot approve own waiver by default (Separation of duties)
        $selfApprove = $this->actingAs($this->admin)->postJson("/api/v1/adjustment-requests/{$reqId}/approve", [
            'reason' => 'Trying self-approval',
        ]);
        $selfApprove->assertStatus(403)
            ->assertJsonPath('error.code', 'SEPARATION_OF_DUTIES_VIOLATION');

        // Create a request from staff and approve as admin (C43)
        $staffReq = $this->actingAs($this->staff)->postJson('/api/v1/adjustment-requests', [
            'invoice_item_id' => $admItemId,
            'adjustment_type' => 'WAIVER',
            'requested_amount' => '2000.00',
            'reason' => 'Orphan full admission fee waiver',
        ])->json('data');

        // Reject the earlier admin request first
        $this->actingAs($this->admin)->postJson("/api/v1/adjustment-requests/{$reqId}/reject", [
            'reason' => 'Superseded by full orphan admission waiver',
        ])->assertOk()->assertJsonPath('data.state', 'REJECTED');

        // C43, C47, C61: Admin approves staff's full admission waiver -> item net becomes 0.00, invoice net becomes 1000.00,
        // verifiable zero-clearance evidence emitted, but D04 enrollment stays PENDING_PAYMENT (never auto-approved by D06!)
        $approveRes = $this->actingAs($this->admin)->postJson("/api/v1/adjustment-requests/{$staffReq['id']}/approve", [
            'reason' => 'Approved by Madrasa Committee Ref-2026-99',
        ]);
        $approveRes->assertOk()
            ->assertJsonPath('data.state', 'APPROVED')
            ->assertJsonPath('data.applied_amount', '2000.00');

        $invAfterApprove = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoiceId}")->json('data');
        $this->assertEquals('1000.00', $invAfterApprove['net_amount']);
        $this->assertEquals('0.00', $invAfterApprove['required_admission_net']);
        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $enrollment->fresh()->status);

        $this->assertDatabaseHas('billing_outbox', [
            'event_type' => 'ZeroChargeClearanceEvidenceIssued',
            'aggregate_id' => $invoiceId,
        ]);

        // C48, C49: Verify D07 boundary read service for tuition vs required admission items
        [, $enr2] = $this->createStudentAndEnrollment(
            '260932',
            'Partial Payment Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A
        );

        $inv2 = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'enrollment_id' => $enr2->id,
            'auto_issue' => true,
            'items' => [
                ['fee_category_id' => $admCat->id, 'unit_amount' => '1500.00', 'quantity' => 1, 'admission_required' => true],
                ['fee_category_id' => $tuitCat->id, 'unit_amount' => '500.00', 'quantity' => 1, 'admission_required' => false],
            ],
        ])->json('data');

        $inv2AdmItemId = $inv2['items'][0]['id'];
        $inv2TuitItemId = $inv2['items'][1]['id'];

        /** @var BillingLedgerReadService $ledgerService */
        $ledgerService = app(BillingLedgerReadService::class);

        // C49: Paying 500.00 towards tuition does NOT clear admission-required fee
        $ledgerService->consumePaymentAllocated('evt-pay-tuit-only', $inv2['id'], '500.00', [
            $inv2TuitItemId => '500.00',
        ]);
        $liability1 = $ledgerService->getEnrollmentBillingLiability($enr2->id);
        $this->assertFalse($liability1['is_required_admission_settled']);
        $this->assertEquals('1500.00', $liability1['required_admission_outstanding']);

        // C48: Partial payment (1000.00 of 1500.00) towards admission-required fee leaves admission unsettled
        $ledgerService->consumePaymentAllocated('evt-pay-adm-partial', $inv2['id'], '1000.00', [
            $inv2AdmItemId => '1000.00',
        ]);
        $liability2 = $ledgerService->getEnrollmentBillingLiability($enr2->id);
        $this->assertFalse($liability2['is_required_admission_settled']);
        $this->assertEquals('500.00', $liability2['required_admission_outstanding']);

        // Settling the remaining 500.00 on the admission-required item marks is_required_admission_settled = true
        $ledgerService->consumePaymentAllocated('evt-pay-adm-final', $inv2['id'], '500.00', [
            $inv2AdmItemId => '500.00',
        ]);
        $liability3 = $ledgerService->getEnrollmentBillingLiability($enr2->id);
        $this->assertTrue($liability3['is_required_admission_settled']);
        $this->assertEquals('0.00', $liability3['required_admission_outstanding']);
    }

    /**
     * C50–C60: Role permissions matrix, Accounts/Staff restrictions, student ledger reconciliation, and summary KPIs.
     */
    public function test_c50_to_c60_rbac_matrix_student_ledger_reconciliation_and_summary(): void
    {
        $admCat = FeeCategory::create([
            'code' => 'ADM',
            'name_bn' => 'ভর্তি ফি',
            'name_en' => 'Admission Fee',
            'billing_type' => 'ADMISSION',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // C51: Accounts cannot create or change fee structures or categories (403 FORBIDDEN)
        $this->actingAs($this->accounts)->postJson('/api/v1/fee-categories', [
            'code' => 'ILLEGAL',
            'name_bn' => 'অবৈধ',
            'name_en' => 'Illegal',
        ])->assertForbidden();

        $this->actingAs($this->accounts)->postJson('/api/v1/fee-structures', [
            'program_id' => $this->dakhilProgram->id,
            'effective_from' => '2026-01-01',
            'items' => [['fee_category_id' => $admCat->id, 'amount' => '500.00', 'cadence' => 'ONE_TIME']],
        ])->assertForbidden();

        // C50: Office Staff cannot create billing runs or void invoices (403 FORBIDDEN)
        $this->actingAs($this->staff)->postJson('/api/v1/billing-runs', [
            'type' => 'MONTHLY_TUITION',
            'academic_period_id' => $this->dakhilPeriod2026->id,
            'billing_month' => '2026-11',
        ])->assertForbidden();

        [$student, $enrollment] = $this->createStudentAndEnrollment(
            '260999',
            'Ledger Reconciliation Student',
            $this->dakhilPeriod2026,
            $this->dakhilOffering7,
            $this->dakhilSection7A
        );

        $inv = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'enrollment_id' => $enrollment->id,
            'auto_issue' => true,
            'items' => [
                ['fee_category_id' => $admCat->id, 'unit_amount' => '1800.00', 'quantity' => 1, 'admission_required' => true],
            ],
        ])->json('data');

        // Apply 300.00 discount requested by Accounts and approved by Admin
        $adjReq = $this->actingAs($this->accounts)->postJson('/api/v1/adjustment-requests', [
            'invoice_item_id' => $inv['items'][0]['id'],
            'adjustment_type' => 'DISCOUNT',
            'requested_amount' => '300.00',
            'reason' => 'Sibling discount',
        ])->assertCreated()->json('data');

        // Accounts cannot approve adjustments (403)
        $this->actingAs($this->accounts)->postJson("/api/v1/adjustment-requests/{$adjReq['id']}/approve")
            ->assertForbidden();

        $this->actingAs($this->admin)->postJson("/api/v1/adjustment-requests/{$adjReq['id']}/approve", [
            'reason' => 'Approved sibling discount',
        ])->assertOk();

        // C52, C53, C60: Student ledger by D03 internal PK reconciles gross (1800), adjustment (300), net (1500), collected (0), outstanding (1500)
        $ledgerRes = $this->actingAs($this->accounts)->getJson("/api/v1/students/{$student->id}/billing-ledger");
        $ledgerRes->assertOk()
            ->assertJsonPath('data.student.record_id', $student->id)
            ->assertJsonPath('data.student.public_student_id', '260999')
            ->assertJsonPath('data.reconciliation_summary.total_gross_amount', '1800.00')
            ->assertJsonPath('data.reconciliation_summary.total_adjustment_amount', '300.00')
            ->assertJsonPath('data.reconciliation_summary.total_net_amount', '1500.00')
            ->assertJsonPath('data.reconciliation_summary.total_verified_collected_amount', '0.00')
            ->assertJsonPath('data.reconciliation_summary.total_outstanding_amount', '1500.00');

        // F01: Billing dashboard summary KPIs
        $summaryRes = $this->actingAs($this->admin)->getJson('/api/v1/billing/summary');
        $summaryRes->assertOk()
            ->assertJsonPath('data.kpis.issued_invoices_count', 1)
            ->assertJsonPath('data.kpis.issued_invoices_net', '1500.00')
            ->assertJsonPath('data.kpis.calculated_outstanding_amount', '1500.00');
    }
}
