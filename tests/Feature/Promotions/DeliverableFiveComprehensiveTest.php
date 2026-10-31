<?php

namespace Tests\Feature\Promotions;

use App\Enums\AcademicScheme;
use App\Enums\AccountStatus;
use App\Enums\AdmissionStatus;
use App\Enums\CohortStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use App\Enums\PeriodState;
use App\Enums\PromotionAction;
use App\Enums\PromotionBatchStatus;
use App\Enums\PromotionItemStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCohort;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Department;
use App\Models\EducationAuthority;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\EnrollmentOutbox;
use App\Models\OfferingSection;
use App\Models\ProgramAdmission;
use App\Models\ProgramClass;
use App\Models\PromotionBatch;
use App\Models\PromotionItem;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverableFiveComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $staffUser;
    protected User $accountsUser;

    protected AcademicProgram $calendarProgram;
    protected ProgramClass $calendarClass7;
    protected ProgramClass $calendarClass8;
    protected AcademicPeriod $calendarPeriod2026;
    protected AcademicPeriod $calendarPeriod2027;
    protected AcademicOffering $offering2026Class7;
    protected OfferingSection $section2026Class7A;
    protected AcademicOffering $offering2027Class8;
    protected OfferingSection $section2027Class8A;
    protected AcademicOffering $offering2027Class7;
    protected OfferingSection $section2027Class7A;

    protected AcademicProgram $sessionProgram;
    protected AcademicCohort $sessionCohortA;
    protected AcademicCohort $sessionCohortB;
    protected ProgramClass $sessionClassYear1;
    protected ProgramClass $sessionClassYear2;
    protected AcademicPeriod $sessionPeriodYear1;
    protected AcademicPeriod $sessionPeriodYear2SameCohort;
    protected AcademicPeriod $sessionPeriodYear2DiffCohort;
    protected AcademicOffering $sessionOfferingYear2SameCohort;
    protected OfferingSection $sessionSectionYear2SameCohort;

    protected Student $studentA;
    protected Student $studentB;
    protected Enrollment $enrollmentA;
    protected Enrollment $enrollmentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->adminUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'অ্যাডমিন',
            'name_en' => 'Admin User',
            'username' => 'd05.admin',
            'email' => 'admin@d05.test',
            'password' => bcrypt('AdminPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->adminUser->roles()->attach($adminRole->id);

        $this->staffUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'স্টাফ',
            'name_en' => 'Staff User',
            'username' => 'd05.staff',
            'email' => 'staff@d05.test',
            'password' => bcrypt('StaffPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staffUser->roles()->attach($staffRole->id);

        $this->accountsUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'হিসাব শাখা',
            'name_en' => 'Accounts User',
            'username' => 'd05.accounts',
            'email' => 'accounts@d05.test',
            'password' => bcrypt('AccountsPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accountsUser->roles()->attach($accountsRole->id);

        // Academic Structure setup
        $authority = EducationAuthority::create([
            'code' => 'BEF-D05',
            'name_bn' => 'বেফাকুল মাদারিসিল আরাবিয়া',
            'name_en' => 'Befaqul Madarisil Arabia',
            'is_active' => true,
        ]);

        $level = EducationLevel::create([
            'authority_id' => $authority->id,
            'code' => 'SAN-D05',
            'name_bn' => 'সানাবিয়া উলইয়া',
            'name_en' => 'Sanabia Ulya',
            'tier_order' => 1,
            'is_active' => true,
        ]);

        $dept = Department::create([
            'authority_id' => $authority->id,
            'code' => 'GEN-D05',
            'name_bn' => 'সাধারণ বিভাগ',
            'name_en' => 'General Academic Department',
            'is_active' => true,
        ]);

        // 1. Calendar Program: Dakhil
        $this->calendarProgram = AcademicProgram::create([
            'department_id' => $dept->id,
            'education_level_id' => $level->id,
            'code' => 'DAK-D05',
            'name_bn' => 'দাখিল',
            'name_en' => 'Dakhil Program',
            'scheme' => AcademicScheme::CALENDAR_YEAR,
            'duration_years' => 5,
            'state' => 'ACTIVE',
        ]);

        $this->calendarClass7 = ProgramClass::create([
            'program_id' => $this->calendarProgram->id,
            'code' => 'CLS-7',
            'name_bn' => 'সপ্তম শ্রেণি',
            'name_en' => 'Class 7',
            'stage_number' => 7,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->calendarClass8 = ProgramClass::create([
            'program_id' => $this->calendarProgram->id,
            'code' => 'CLS-8',
            'name_bn' => 'অষ্টম শ্রেণি',
            'name_en' => 'Class 8',
            'stage_number' => 8,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->calendarPeriod2026 = AcademicPeriod::create([
            'program_id' => $this->calendarProgram->id,
            'cohort_id' => null,
            'label' => 'Academic Year 2026',
            'calendar_year' => 2026,
            'study_year_number' => null,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->calendarPeriod2027 = AcademicPeriod::create([
            'program_id' => $this->calendarProgram->id,
            'cohort_id' => null,
            'label' => 'Academic Year 2027',
            'calendar_year' => 2027,
            'study_year_number' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        // Offering & Section 2026 Class 7
        $this->offering2026Class7 = AcademicOffering::create([
            'academic_period_id' => $this->calendarPeriod2026->id,
            'program_class_id' => $this->calendarClass7->id,
            'academic_track_id' => null,
            'is_active' => true,
        ]);

        $this->section2026Class7A = OfferingSection::create([
            'academic_offering_id' => $this->offering2026Class7->id,
            'code' => 'SEC-26-7A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 10,
            'is_active' => true,
        ]);

        // Offering & Section 2027 Class 8 (Target for promotion)
        $this->offering2027Class8 = AcademicOffering::create([
            'academic_period_id' => $this->calendarPeriod2027->id,
            'program_class_id' => $this->calendarClass8->id,
            'academic_track_id' => null,
            'is_active' => true,
        ]);

        $this->section2027Class8A = OfferingSection::create([
            'academic_offering_id' => $this->offering2027Class8->id,
            'code' => 'SEC-27-8A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 5,
            'is_active' => true,
        ]);

        // Offering & Section 2027 Class 7 (Target for repeat)
        $this->offering2027Class7 = AcademicOffering::create([
            'academic_period_id' => $this->calendarPeriod2027->id,
            'program_class_id' => $this->calendarClass7->id,
            'academic_track_id' => null,
            'is_active' => true,
        ]);

        $this->section2027Class7A = OfferingSection::create([
            'academic_offering_id' => $this->offering2027Class7->id,
            'code' => 'SEC-27-7A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 5,
            'is_active' => true,
        ]);

        // 2. Session Program: Alim
        $this->sessionProgram = AcademicProgram::create([
            'department_id' => $dept->id,
            'education_level_id' => $level->id,
            'code' => 'ALM-D05',
            'name_bn' => 'আলিম',
            'name_en' => 'Alim Program',
            'scheme' => AcademicScheme::SESSION_BASED,
            'duration_years' => 2,
            'state' => 'ACTIVE',
        ]);

        $this->sessionCohortA = AcademicCohort::create([
            'program_id' => $this->sessionProgram->id,
            'label' => 'Cohort 2025-2027',
            'intake_year' => 2025,
            'status' => CohortStatus::ACTIVE,
        ]);

        $this->sessionCohortB = AcademicCohort::create([
            'program_id' => $this->sessionProgram->id,
            'label' => 'Cohort 2026-2028',
            'intake_year' => 2026,
            'status' => CohortStatus::ACTIVE,
        ]);

        $this->sessionClassYear1 = ProgramClass::create([
            'program_id' => $this->sessionProgram->id,
            'code' => 'ALM-Y1',
            'name_bn' => 'আলিম ১ম বর্ষ',
            'name_en' => 'Alim 1st Year',
            'stage_number' => 1,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->sessionClassYear2 = ProgramClass::create([
            'program_id' => $this->sessionProgram->id,
            'code' => 'ALM-Y2',
            'name_bn' => 'আলিম ২য় বর্ষ',
            'name_en' => 'Alim 2nd Year',
            'stage_number' => 2,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->sessionPeriodYear1 = AcademicPeriod::create([
            'program_id' => $this->sessionProgram->id,
            'cohort_id' => $this->sessionCohortA->id,
            'label' => 'Alim Cohort 2025-27 Year 1',
            'calendar_year' => null,
            'study_year_number' => 1,
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->sessionPeriodYear2SameCohort = AcademicPeriod::create([
            'program_id' => $this->sessionProgram->id,
            'cohort_id' => $this->sessionCohortA->id,
            'label' => 'Alim Cohort 2025-27 Year 2',
            'calendar_year' => null,
            'study_year_number' => 2,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->sessionPeriodYear2DiffCohort = AcademicPeriod::create([
            'program_id' => $this->sessionProgram->id,
            'cohort_id' => $this->sessionCohortB->id,
            'label' => 'Alim Cohort 2026-28 Year 1',
            'calendar_year' => null,
            'study_year_number' => 1,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'state' => PeriodState::OPEN,
            'lock_version' => 1,
        ]);

        $this->sessionOfferingYear2SameCohort = AcademicOffering::create([
            'academic_period_id' => $this->sessionPeriodYear2SameCohort->id,
            'program_class_id' => $this->sessionClassYear2->id,
            'academic_track_id' => null,
            'is_active' => true,
        ]);

        $this->sessionSectionYear2SameCohort = OfferingSection::create([
            'academic_offering_id' => $this->sessionOfferingYear2SameCohort->id,
            'code' => 'ALM-SEC-Y2A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 10,
            'is_active' => true,
        ]);

        // Students & Source Enrollments
        $this->studentA = Student::create([
            'student_id' => '26010001',
            'name_bn' => 'আব্দুল্লাহ আল মামুন',
            'name_en' => 'Abdullah Al Mamun',
            'status' => StudentStatus::ACTIVE,
            'gender' => 'MALE',
            'date_of_birth' => '2012-05-15',
            'admission_date' => '2026-01-10',
            'created_by' => $this->adminUser->id,
        ]);

        $admissionA = ProgramAdmission::create([
            'student_id' => $this->studentA->id,
            'academic_program_id' => $this->calendarProgram->id,
            'academic_cohort_id' => null,
            'entry_type' => 'REGULAR',
            'status' => AdmissionStatus::ACTIVE,
            'entered_at' => now(),
            'lock_version' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $this->enrollmentA = Enrollment::create([
            'program_admission_id' => $admissionA->id,
            'student_id' => $this->studentA->id,
            'academic_period_id' => $this->calendarPeriod2026->id,
            'academic_offering_id' => $this->offering2026Class7->id,
            'offering_section_id' => $this->section2026Class7A->id,
            'enrollment_type' => EnrollmentType::NEW,
            'status' => EnrollmentStatus::APPROVED,
            'billing_cleared' => true,
            'payment_cleared' => true,
            'lock_version' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);

        $this->studentB = Student::create([
            'student_id' => '26010002',
            'name_bn' => 'মুহাম্মদ ইব্রাহীম',
            'name_en' => 'Muhammad Ibrahim',
            'status' => StudentStatus::ACTIVE,
            'gender' => 'MALE',
            'date_of_birth' => '2012-06-20',
            'admission_date' => '2026-01-10',
            'created_by' => $this->adminUser->id,
        ]);

        $admissionB = ProgramAdmission::create([
            'student_id' => $this->studentB->id,
            'academic_program_id' => $this->calendarProgram->id,
            'academic_cohort_id' => null,
            'entry_type' => 'REGULAR',
            'status' => AdmissionStatus::ACTIVE,
            'entered_at' => now(),
            'lock_version' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $this->enrollmentB = Enrollment::create([
            'program_admission_id' => $admissionB->id,
            'student_id' => $this->studentB->id,
            'academic_period_id' => $this->calendarPeriod2026->id,
            'academic_offering_id' => $this->offering2026Class7->id,
            'offering_section_id' => $this->section2026Class7A->id,
            'enrollment_type' => EnrollmentType::NEW,
            'status' => EnrollmentStatus::APPROVED,
            'billing_cleared' => true,
            'payment_cleared' => true,
            'lock_version' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);
    }

    /**
     * D05-AT-01 to D05-AT-05: Read-Only Previews & Eligibility Validation
     */
    public function test_individual_promotion_preview_is_purely_read_only_with_zero_side_effects(): void
    {
        $payload = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        $enrollmentsBefore = Enrollment::count();
        $batchesBefore = PromotionBatch::count();
        $itemsBefore = PromotionItem::count();
        $outboxBefore = EnrollmentOutbox::count();

        $response = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_eligible', true)
            ->assertJsonPath('data.action', 'PROMOTE')
            ->assertJsonPath('data.student.student_id', '26010001')
            ->assertJsonPath('data.source_enrollment.stage_number', 7)
            ->assertJsonPath('data.target_offering_section.stage_number', 8);

        // Strict assertion: zero side-effects
        $this->assertEquals($enrollmentsBefore, Enrollment::count());
        $this->assertEquals($batchesBefore, PromotionBatch::count());
        $this->assertEquals($itemsBefore, PromotionItem::count());
        $this->assertEquals($outboxBefore, EnrollmentOutbox::count());
    }

    public function test_individual_promotion_requires_approved_source_enrollment(): void
    {
        // Set source enrollment status to PENDING_BILLING
        $this->enrollmentA->update(['status' => EnrollmentStatus::PENDING_BILLING]);

        $payload = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        // Preview returns ineligible with SOURCE_NOT_APPROVED
        $response = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_eligible', false)
            ->assertJsonPath('data.errors.0.code', 'SOURCE_NOT_APPROVED');

        // Execution fails with 422 SOURCE_NOT_APPROVED
        $execResponse = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual', $payload);

        $execResponse->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_NOT_APPROVED');
    }

    /**
     * D05-AT-06 to D05-AT-12: Stage Progression & Scheme Invariants
     */
    public function test_stage_progression_invariants_for_promote_and_repeat(): void
    {
        // 1. Valid Promotion: Class 7 -> Class 8
        $payloadPromote = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        $resPromote = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payloadPromote);
        $resPromote->assertStatus(200)->assertJsonPath('data.is_eligible', true);

        // 2. Valid Repeat: Class 7 -> Class 7
        $payloadRepeat = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class7A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'REPEAT',
            'academic_eligibility_confirmed' => true,
        ];

        $resRepeat = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payloadRepeat);
        $resRepeat->assertStatus(200)->assertJsonPath('data.is_eligible', true);

        // 3. Invalid: Action is REPEAT but target is Class 8 (different stage)
        $payloadInvalidRepeat = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'REPEAT',
            'academic_eligibility_confirmed' => true,
        ];

        $resInvalidRepeat = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payloadInvalidRepeat);
        $resInvalidRepeat->assertStatus(200)
            ->assertJsonPath('data.is_eligible', false)
            ->assertJsonPath('data.errors.0.code', 'INVALID_STAGE_TRANSITION');
    }

    public function test_session_based_cohort_invariance_and_closed_period(): void
    {
        // Create student in Session Program Year 1
        $studentS = Student::create([
            'student_id' => '25010099',
            'name_bn' => 'সেশন ছাত্র',
            'name_en' => 'Session Student',
            'status' => StudentStatus::ACTIVE,
            'gender' => 'MALE',
            'date_of_birth' => '2010-01-01',
            'admission_date' => '2025-07-01',
            'created_by' => $this->adminUser->id,
        ]);

        $admissionS = ProgramAdmission::create([
            'student_id' => $studentS->id,
            'academic_program_id' => $this->sessionProgram->id,
            'academic_cohort_id' => $this->sessionCohortA->id,
            'entry_type' => 'REGULAR',
            'status' => AdmissionStatus::ACTIVE,
            'entered_at' => now(),
            'lock_version' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $offeringY1 = AcademicOffering::create([
            'academic_period_id' => $this->sessionPeriodYear1->id,
            'program_class_id' => $this->sessionClassYear1->id,
            'is_active' => true,
        ]);

        $sectionY1 = OfferingSection::create([
            'academic_offering_id' => $offeringY1->id,
            'code' => 'ALM-SEC-Y1',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $enrollmentS = Enrollment::create([
            'program_admission_id' => $admissionS->id,
            'student_id' => $studentS->id,
            'academic_period_id' => $this->sessionPeriodYear1->id,
            'academic_offering_id' => $offeringY1->id,
            'offering_section_id' => $sectionY1->id,
            'enrollment_type' => EnrollmentType::NEW,
            'status' => EnrollmentStatus::APPROVED,
            'billing_cleared' => true,
            'payment_cleared' => true,
            'lock_version' => 1,
            'submitted_at' => now(),
            'approved_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);

        // Attempt progression to Year 2 of a DIFFERENT cohort (Cohort B)
        $payloadMismatch = [
            'student_id' => $studentS->id,
            'source_enrollment_id' => $enrollmentS->id,
            'target_offering_section_id' => $this->sessionSectionYear2SameCohort->id,
            'target_academic_period_id' => $this->sessionPeriodYear2DiffCohort->id, // Mismatch cohort
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        $resMismatch = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payloadMismatch);

        $resMismatch->assertStatus(200)
            ->assertJsonPath('data.is_eligible', false);

        // Attempt progression when target period is CLOSED
        $this->sessionPeriodYear2SameCohort->update(['state' => PeriodState::CLOSED]);

        $payloadClosed = [
            'student_id' => $studentS->id,
            'source_enrollment_id' => $enrollmentS->id,
            'target_offering_section_id' => $this->sessionSectionYear2SameCohort->id,
            'target_academic_period_id' => $this->sessionPeriodYear2SameCohort->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        $resClosed = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payloadClosed);

        $resClosed->assertStatus(200)
            ->assertJsonPath('data.is_eligible', false)
            ->assertJsonPath('data.errors.0.code', 'TARGET_PERIOD_CLOSED');
    }

    /**
     * D05-AT-13 to D05-AT-18: Capacity, Concurrency & Idempotency
     */
    public function test_target_section_capacity_overflow_is_prevented(): void
    {
        // Set target section capacity to 1 and fill it with an existing active enrollment
        $this->section2027Class8A->update(['capacity' => 1]);

        Enrollment::create([
            'program_admission_id' => $this->enrollmentB->program_admission_id,
            'student_id' => $this->studentB->id,
            'academic_period_id' => $this->calendarPeriod2027->id,
            'academic_offering_id' => $this->offering2027Class8->id,
            'offering_section_id' => $this->section2027Class8A->id,
            'enrollment_type' => EnrollmentType::NEW,
            'status' => EnrollmentStatus::APPROVED,
            'billing_cleared' => true,
            'payment_cleared' => true,
            'lock_version' => 1,
            'submitted_at' => now(),
            'created_by' => $this->adminUser->id,
        ]);

        $payload = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
        ];

        $response = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/individual/preview', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_eligible', false)
            ->assertJsonPath('data.errors.0.code', 'SECTION_FULL');
    }

    public function test_individual_promotion_execution_and_idempotency(): void
    {
        $payload = [
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'action' => 'PROMOTE',
            'academic_eligibility_confirmed' => true,
            'notes' => 'Annual performance promotion',
        ];

        $idempotencyKey = 'IDEM-TEST-' . uniqid();

        // 1. Initial Execution
        $response1 = $this->actingAs($this->staffUser)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/promotions/individual', $payload);

        $response1->assertStatus(201)
            ->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.target_enrollment.status', 'PENDING_BILLING')
            ->assertJsonPath('data.target_enrollment.enrollment_type', 'CONTINUING');

        $targetEnrollmentId = $response1->json('data.target_enrollment_id');

        // Verify permanent student ID is unchanged
        $this->studentA->refresh();
        $this->assertEquals('26010001', $this->studentA->student_id);

        // Verify historical enrollment is still intact in APPROVED
        $this->enrollmentA->refresh();
        $this->assertEquals(EnrollmentStatus::APPROVED, $this->enrollmentA->status);

        // Verify destination enrollment is strictly in PENDING_BILLING
        $newEnrollment = Enrollment::findOrFail($targetEnrollmentId);
        $this->assertEquals(EnrollmentStatus::PENDING_BILLING, $newEnrollment->status);
        $this->assertFalse($newEnrollment->billing_cleared);
        $this->assertFalse($newEnrollment->payment_cleared);

        // Verify outbox event was recorded
        $this->assertDatabaseHas('enrollment_outbox', [
            'aggregate_id' => $targetEnrollmentId,
            'event_type' => 'EnrollmentSubmitted',
        ]);

        // 2. Re-submit exact same request with same idempotency key -> returns exact cached response
        $response2 = $this->actingAs($this->staffUser)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/promotions/individual', $payload);

        $response2->assertStatus(201)
            ->assertJsonPath('data.target_enrollment_id', $targetEnrollmentId);

        // 3. Re-submit same idempotency key with modified payload -> returns 409 IDEMPOTENCY_PAYLOAD_MISMATCH
        $modifiedPayload = $payload;
        $modifiedPayload['notes'] = 'Tampered payload notes';

        $response3 = $this->actingAs($this->staffUser)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/promotions/individual', $modifiedPayload);

        $response3->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    /**
     * D05-AT-26 to D05-AT-35: Bulk Promotion Batch Management & Execution
     */
    public function test_bulk_promotion_batch_lifecycle_and_execution(): void
    {
        // Create draft batch
        $createPayload = [
            'mode' => 'BULK',
            'action' => 'PROMOTE',
            'source_academic_period_id' => $this->calendarPeriod2026->id,
            'source_offering_section_id' => $this->section2026Class7A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'target_academic_offering_id' => $this->offering2027Class8->id,
            'academic_eligibility_confirmed' => true,
            'notes' => 'Batch promotion 2026 to 2027',
        ];

        $createRes = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/promotions/batches', $createPayload);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.total_items', 2);

        $batchId = $createRes->json('data.id');
        $batchNumber = $createRes->json('data.batch_number');

        $this->assertStringStartsWith('PRM-', $batchNumber);

        // Run Batch Preview
        $previewRes = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/promotions/batches/{$batchId}/preview");

        $previewRes->assertStatus(200)
            ->assertJsonPath('data.status', 'PREVIEWED')
            ->assertJsonPath('data.eligible_count', 2)
            ->assertJsonPath('data.ineligible_count', 0);

        // Execute Batch
        $execRes = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/promotions/batches/{$batchId}/execute");

        $execRes->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.items_count.succeeded', 2)
            ->assertJsonPath('data.items_count.failed', 0);

        // Verify items succeeded
        $this->assertDatabaseHas('promotion_items', [
            'batch_id' => $batchId,
            'student_id' => $this->studentA->id,
            'status' => 'SUCCEEDED',
        ]);
        $this->assertDatabaseHas('promotion_items', [
            'batch_id' => $batchId,
            'student_id' => $this->studentB->id,
            'status' => 'SUCCEEDED',
        ]);
    }

    public function test_bulk_batch_partial_failure_isolation_and_retry(): void
    {
        // Set target section capacity to 1 (Student A will succeed, Student B will fail due to capacity)
        $this->section2027Class8A->update(['capacity' => 1]);

        $batch = PromotionBatch::create([
            'batch_number' => 'PRM-2027-PARTIAL-1',
            'mode' => 'BULK',
            'action' => PromotionAction::PROMOTE,
            'source_academic_period_id' => $this->calendarPeriod2026->id,
            'source_offering_section_id' => $this->section2026Class7A->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'target_academic_offering_id' => $this->offering2027Class8->id,
            'status' => PromotionBatchStatus::DRAFT,
            'lock_version' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $itemA = PromotionItem::create([
            'batch_id' => $batch->id,
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'action' => PromotionAction::PROMOTE,
            'academic_eligibility_confirmed' => true,
            'status' => PromotionItemStatus::PENDING,
        ]);

        $itemB = PromotionItem::create([
            'batch_id' => $batch->id,
            'student_id' => $this->studentB->id,
            'source_enrollment_id' => $this->enrollmentB->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'action' => PromotionAction::PROMOTE,
            'academic_eligibility_confirmed' => true,
            'status' => PromotionItemStatus::PENDING,
        ]);

        // Execute batch
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/promotions/batches/{$batch->id}/execute");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED_WITH_ERRORS')
            ->assertJsonPath('data.items_count.succeeded', 1)
            ->assertJsonPath('data.items_count.failed', 1);

        $itemA->refresh();
        $itemB->refresh();

        $this->assertEquals(PromotionItemStatus::SUCCEEDED, $itemA->status);
        $this->assertEquals(PromotionItemStatus::FAILED, $itemB->status);
        $this->assertEquals('SECTION_FULL', $itemB->error_code);

        // Expand capacity and retry
        $this->section2027Class8A->update(['capacity' => 10]);

        $retryRes = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/promotions/batches/{$batch->id}/retry");

        $retryRes->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.items_count.succeeded', 2)
            ->assertJsonPath('data.items_count.failed', 0);

        $itemB->refresh();
        $this->assertEquals(PromotionItemStatus::SUCCEEDED, $itemB->status);
    }

    /**
     * D05-AT-43 to D05-AT-48: Audit Trail & Formula-Sanitized UTF-8 BOM CSV Export
     */
    public function test_csv_export_includes_bom_and_formula_sanitization(): void
    {
        $batch = PromotionBatch::create([
            'batch_number' => 'PRM-2027-EXPORT-1',
            'mode' => 'BULK',
            'action' => PromotionAction::PROMOTE,
            'source_academic_period_id' => $this->calendarPeriod2026->id,
            'target_academic_period_id' => $this->calendarPeriod2027->id,
            'target_academic_offering_id' => $this->offering2027Class8->id,
            'status' => PromotionBatchStatus::COMPLETED,
            'created_by' => $this->adminUser->id,
        ]);

        // Add an item with a formula trigger in student name
        $this->studentA->update(['name_en' => '=cmd|/c calc']);

        PromotionItem::create([
            'batch_id' => $batch->id,
            'student_id' => $this->studentA->id,
            'source_enrollment_id' => $this->enrollmentA->id,
            'target_offering_section_id' => $this->section2027Class8A->id,
            'action' => PromotionAction::PROMOTE,
            'academic_eligibility_confirmed' => true,
            'status' => PromotionItemStatus::SUCCEEDED,
        ]);

        $response = $this->actingAs($this->staffUser)
            ->get("/api/v1/promotions/batches/{$batch->id}/export");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Check BOM
        $bom = chr(0xEF) . chr(0xBB) . chr(0xBF);
        $this->assertStringStartsWith($bom, $content);

        // Check Formula Sanitization: '=cmd|/c calc' must be prefixed with single quote "'=cmd|/c calc"
        $this->assertStringContainsString("'=cmd|/c calc", $content);
    }

    /**
     * D05-AT-49 to D05-AT-56: Access Control & Accounts Role Rejection
     */
    public function test_accounts_role_is_strictly_forbidden_across_all_promotion_endpoints(): void
    {
        // 1. Preview
        $previewRes = $this->actingAs($this->accountsUser)
            ->postJson('/api/v1/promotions/individual/preview', []);
        $previewRes->assertStatus(403);

        // 2. Individual execute
        $execRes = $this->actingAs($this->accountsUser)
            ->postJson('/api/v1/promotions/individual', []);
        $execRes->assertStatus(403);

        // 3. Batch list
        $batchListRes = $this->actingAs($this->accountsUser)
            ->getJson('/api/v1/promotions/batches');
        $batchListRes->assertStatus(403);

        // 4. Batch create
        $batchCreateRes = $this->actingAs($this->accountsUser)
            ->postJson('/api/v1/promotions/batches', []);
        $batchCreateRes->assertStatus(403);
    }
}

