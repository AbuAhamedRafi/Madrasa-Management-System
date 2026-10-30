<?php

namespace Tests\Feature\Admissions;

use App\Enums\AcademicScheme;
use App\Enums\AccountStatus;
use App\Enums\AdmissionEntryType;
use App\Enums\AdmissionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
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
use App\Models\EnrollmentStatusEvent;
use App\Models\OfferingSection;
use App\Models\Permission;
use App\Models\ProgramAdmission;
use App\Models\ProgramClass;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Admissions\AdmissionApprovalService;
use App\Services\Admissions\EnrollmentBillingStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverableFourComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $staffUser;
    protected User $accountsUser;

    protected AcademicProgram $calendarProgram;
    protected AcademicPeriod $calendarPeriod;
    protected ProgramClass $calendarClass;
    protected AcademicOffering $calendarOffering;
    protected OfferingSection $calendarSectionA;
    protected OfferingSection $calendarSectionB;

    protected AcademicProgram $sessionProgram;
    protected AcademicCohort $sessionCohort;
    protected AcademicPeriod $sessionPeriodYear1;
    protected AcademicPeriod $sessionPeriodYear2;
    protected ProgramClass $sessionClassYear1;
    protected ProgramClass $sessionClassYear2;
    protected AcademicOffering $sessionOfferingYear1;
    protected OfferingSection $sessionSectionYear1;

    protected Student $activeStudent;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions setup
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->adminUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'অ্যাডমিন',
            'name_en' => 'Admin User',
            'username' => 'd04.admin',
            'email' => 'admin@mms.test',
            'password' => bcrypt('AdminPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->adminUser->roles()->attach($adminRole->id);

        $this->staffUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'স্টাফ',
            'name_en' => 'Staff User',
            'username' => 'd04.staff',
            'email' => 'staff@mms.test',
            'password' => bcrypt('StaffPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staffUser->roles()->attach($staffRole->id);

        $this->accountsUser = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'একাউন্টস',
            'name_en' => 'Accounts User',
            'username' => 'd04.accounts',
            'email' => 'accounts@mms.test',
            'password' => bcrypt('AccountsPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accountsUser->roles()->attach($accountsRole->id);

        // 2. Academic Catalog setup
        $auth = EducationAuthority::create(['code' => 'BMEB', 'name_bn' => 'বোর্ড', 'name_en' => 'Board', 'is_active' => true]);
        $dept = Department::create(['code' => 'GEN', 'name_bn' => 'সাধারণ', 'name_en' => 'General', 'is_active' => true]);
        $secLevel = EducationLevel::create(['authority_id' => $auth->id, 'code' => 'SEC', 'name_bn' => 'মাধ্যমিক', 'name_en' => 'Secondary', 'sort_order' => 1]);
        $hsecLevel = EducationLevel::create(['authority_id' => $auth->id, 'code' => 'HSEC', 'name_bn' => 'উচ্চ মাধ্যমিক', 'name_en' => 'Higher Secondary', 'sort_order' => 2]);

        // Calendar-Year Program (e.g. Dakhil)
        $this->calendarProgram = AcademicProgram::create([
            'code' => 'DKH',
            'department_id' => $dept->id,
            'education_level_id' => $secLevel->id,
            'name_bn' => 'দাখিল',
            'name_en' => 'Dakhil',
            'scheme' => AcademicScheme::CALENDAR_YEAR,
            'duration_years' => 5,
            'state' => 'ACTIVE',
        ]);

        $this->calendarClass = ProgramClass::create([
            'program_id' => $this->calendarProgram->id,
            'code' => 'DKH-6',
            'name_bn' => 'দাখিল ষষ্ঠ',
            'name_en' => 'Dakhil 6',
            'stage_number' => 1,
            'sort_order' => 1,
        ]);

        $this->calendarPeriod = AcademicPeriod::create([
            'program_id' => $this->calendarProgram->id,
            'label' => 'দাখিল ২০২৬',
            'calendar_year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'state' => 'OPEN',
        ]);

        $this->calendarOffering = AcademicOffering::create([
            'academic_period_id' => $this->calendarPeriod->id,
            'program_class_id' => $this->calendarClass->id,
            'is_active' => true,
        ]);

        $this->calendarSectionA = OfferingSection::create([
            'academic_offering_id' => $this->calendarOffering->id,
            'code' => 'SEC-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 2, // low capacity for capacity testing
            'is_active' => true,
        ]);

        $this->calendarSectionB = OfferingSection::create([
            'academic_offering_id' => $this->calendarOffering->id,
            'code' => 'SEC-B',
            'name_bn' => 'শাখা খ',
            'name_en' => 'Section B',
            'capacity' => 30,
            'is_active' => true,
        ]);

        // Session-Based Program (e.g. Alim)
        $this->sessionProgram = AcademicProgram::create([
            'code' => 'ALM',
            'department_id' => $dept->id,
            'education_level_id' => $hsecLevel->id,
            'name_bn' => 'আলিম',
            'name_en' => 'Alim',
            'scheme' => AcademicScheme::SESSION_BASED,
            'duration_years' => 2,
            'state' => 'ACTIVE',
        ]);

        $this->sessionCohort = AcademicCohort::create([
            'program_id' => $this->sessionProgram->id,
            'label' => 'আলিম ২০২৬-২০২৮',
            'intake_year' => 2026,
            'duration_years' => 2,
            'status' => 'ACTIVE',
        ]);

        $this->sessionClassYear1 = ProgramClass::create([
            'program_id' => $this->sessionProgram->id,
            'code' => 'ALM-1',
            'name_bn' => 'আলিম ১ম বর্ষ',
            'name_en' => 'Alim 1st Year',
            'stage_number' => 1,
            'sort_order' => 1,
        ]);

        $this->sessionClassYear2 = ProgramClass::create([
            'program_id' => $this->sessionProgram->id,
            'code' => 'ALM-2',
            'name_bn' => 'আলিম ২য় বর্ষ',
            'name_en' => 'Alim 2nd Year',
            'stage_number' => 2,
            'sort_order' => 2,
        ]);

        $this->sessionPeriodYear1 = AcademicPeriod::create([
            'program_id' => $this->sessionProgram->id,
            'cohort_id' => $this->sessionCohort->id,
            'label' => 'আলিম ১ম বর্ষ (২০২৬-২৭)',
            'study_year_number' => 1,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'state' => 'OPEN',
        ]);

        $this->sessionPeriodYear2 = AcademicPeriod::create([
            'program_id' => $this->sessionProgram->id,
            'cohort_id' => $this->sessionCohort->id,
            'label' => 'আলিম ২য় বর্ষ (২০২৭-২৮)',
            'study_year_number' => 2,
            'start_date' => '2027-07-01',
            'end_date' => '2028-06-30',
            'state' => 'OPEN',
        ]);

        $this->sessionOfferingYear1 = AcademicOffering::create([
            'academic_period_id' => $this->sessionPeriodYear1->id,
            'program_class_id' => $this->sessionClassYear1->id,
            'is_active' => true,
        ]);

        $this->sessionSectionYear1 = OfferingSection::create([
            'academic_offering_id' => $this->sessionOfferingYear1->id,
            'code' => 'SEC-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 40,
            'is_active' => true,
        ]);

        // 3. Active Student setup
        $this->activeStudent = Student::create([
            'student_id' => '26010001',
            'name_bn' => 'আব্দুল্লাহ আল মামুন',
            'name_en' => 'Abdullah Al Mamun',
            'date_of_birth' => '2012-05-15',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'status' => StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /**
     * Test AT-01 to AT-06: Student Identifier and Invariant Rules
     */
    public function test_student_identifier_and_invariant_validation(): void
    {
        // AT-01: Rejection of display student_id
        $res01 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_id' => '26010001', // String prohibited!
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res01->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);

        // AT-03: Inactive student is rejected
        $inactiveStudent = Student::create([
            'student_id' => '26010002',
            'name_bn' => 'তাহমিদ আহমেদ',
            'name_en' => 'Tahmid Ahmed',
            'date_of_birth' => '2012-04-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'status' => StudentStatus::INACTIVE,
            'created_by' => $this->adminUser->id,
        ]);

        $res03 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $inactiveStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res03->assertStatus(422)
            ->assertJsonPath('error.code', 'STUDENT_NOT_ACTIVE');

        // AT-02 & AT-19: Valid enrollment creation lands strictly in PENDING_BILLING
        $res02 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
                'notes' => 'New admission entry',
            ]);
        $res02->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_BILLING')
            ->assertJsonPath('data.billing_cleared', false)
            ->assertJsonPath('data.payment_cleared', false);

        $enrollmentId = $res02->json('data.id');

        // AT-20: Status Event created
        $this->assertDatabaseHas('enrollment_status_events', [
            'enrollment_id' => $enrollmentId,
            'from_status' => 'DRAFT',
            'to_status' => 'PENDING_BILLING',
            'source_type' => 'USER_ACTION',
        ]);

        // AT-42: Outbox event emitted
        $this->assertDatabaseHas('enrollment_outbox', [
            'event_type' => 'EnrollmentSubmitted',
            'aggregate_id' => $enrollmentId,
        ]);
    }

    /**
     * Test AT-07 to AT-12: Program Scheme Invariants (Calendar-Year vs Session-Based)
     */
    public function test_program_scheme_invariants(): void
    {
        // AT-07: Calendar-year program rejects cohort assignment
        $res07 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_cohort_id' => $this->sessionCohort->id, // Prohibited for calendar-year!
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res07->assertStatus(422)
            ->assertJsonPath('error.code', 'COHORT_NOT_ALLOWED');

        // AT-08: Session-based program requires cohort
        $res08 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->sessionProgram->id,
                'academic_period_id' => $this->sessionPeriodYear1->id,
                'offering_section_id' => $this->sessionSectionYear1->id,
                // cohort_id missing!
            ]);
        $res08->assertStatus(422)
            ->assertJsonPath('error.code', 'COHORT_REQUIRED');

        // Session-based enrollment succeeds when cohort provided
        $resSessionOk = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->sessionProgram->id,
                'academic_cohort_id' => $this->sessionCohort->id,
                'academic_period_id' => $this->sessionPeriodYear1->id,
                'offering_section_id' => $this->sessionSectionYear1->id,
            ]);
        $resSessionOk->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_BILLING');
    }

    /**
     * Test AT-13 to AT-18: Section Capacity, Over-allocation Protection & Reallocation
     */
    public function test_capacity_and_reallocation_concurrency_protection(): void
    {
        // Section A has capacity of 2. Create student 1 and student 2.
        $student2 = Student::create([
            'student_id' => '26010003',
            'name_bn' => 'হাসান মাহমুদ',
            'name_en' => 'Hasan Mahmud',
            'date_of_birth' => '2012-06-12',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'status' => StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
        ]);

        $student3 = Student::create([
            'student_id' => '26010004',
            'name_bn' => 'সাদিকুর রহমান',
            'name_en' => 'Sadikur Rahman',
            'date_of_birth' => '2012-07-20',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'status' => StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
        ]);

        // Student 1 claims seat 1 of 2
        $res1 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res1->assertStatus(201);
        $enrollment1 = Enrollment::find($res1->json('data.id'));

        // AT-13: Capacity check shows 1 occupied, 1 available
        $capRes = $this->actingAs($this->staffUser)
            ->getJson("/api/v1/admissions/sections/{$this->calendarSectionA->id}/capacity");
        $capRes->assertStatus(200)
            ->assertJsonPath('data.occupied_seats', 1)
            ->assertJsonPath('data.available_seats', 1)
            ->assertJsonPath('data.is_full', false);

        // Student 2 claims seat 2 of 2
        $res2 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $student2->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res2->assertStatus(201);

        // AT-15: Student 3 tries to claim seat in full section -> 409 NO_SEATS_AVAILABLE
        $res3 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $student3->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res3->assertStatus(409)
            ->assertJsonPath('error.code', 'NO_SEATS_AVAILABLE');

        // AT-16: Alternatives picker returns Section B with open seats
        $altRes = $this->actingAs($this->staffUser)
            ->getJson("/api/v1/admissions/offerings/{$this->calendarOffering->id}/alternatives");
        $altRes->assertStatus(200);
        $this->assertTrue(collect($altRes->json('data'))->contains('section_code', 'SEC-B'));

        // AT-18: Reallocate Student 1 from Section A to Section B
        $realRes = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/admissions/enrollments/{$enrollment1->id}/reallocate", [
                'new_section_id' => $this->calendarSectionB->id,
                'reason' => 'Transferring to balance section load',
            ]);
        $realRes->assertStatus(200)
            ->assertJsonPath('data.offering_section_id', $this->calendarSectionB->id);

        // Now Section A has 1 free seat again! Student 3 can now claim seat in Section A!
        $res3Retry = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $student3->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res3Retry->assertStatus(201);
    }

    /**
     * Test AT-21 to AT-25: Billing & Approval Seams, Cancellation & Rejection
     */
    public function test_billing_seam_and_approval_workflow(): void
    {
        $res = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $enrollmentId = $res->json('data.id');

        // AT-21: Billing Seam prepares billing
        $billingService = app(EnrollmentBillingStateService::class);
        $billedEnrollment = $billingService->markPrepared($enrollmentId, 'INV-2026-001', $this->adminUser->id);

        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $billedEnrollment->status);
        $this->assertTrue($billedEnrollment->billing_cleared);

        $this->assertDatabaseHas('enrollment_status_events', [
            'enrollment_id' => $enrollmentId,
            'to_status' => 'PENDING_PAYMENT',
            'source_type' => 'BILLING_SEAM',
        ]);

        // AT-22: Payment Seam approves admission
        $approvalService = app(AdmissionApprovalService::class);
        $approvedEnrollment = $approvalService->approveIfRequiredChargesSettled($enrollmentId, 'RCPT-2026-001', $this->adminUser->id);

        $this->assertEquals(EnrollmentStatus::APPROVED, $approvedEnrollment->status);
        $this->assertTrue($approvedEnrollment->payment_cleared);
        $this->assertNotNull($approvedEnrollment->approved_at);

        // Verify ProgramAdmission also became ACTIVE
        $this->assertEquals(AdmissionStatus::ACTIVE, $approvedEnrollment->programAdmission->fresh()->status);

        // AT-23: Terminal enrollment cannot be reallocated
        $terminalRealloc = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/admissions/enrollments/{$enrollmentId}/reallocate", [
                'new_section_id' => $this->calendarSectionB->id,
                'reason' => 'Should fail because already approved',
            ]);
        $terminalRealloc->assertStatus(409);
    }

    /**
     * Test AT-26 to AT-28: Duplicate Active Enrollment Prevention in Same Period
     */
    public function test_duplicate_active_enrollment_prevention(): void
    {
        // 1st enrollment
        $res1 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $res1->assertStatus(201);
        $enrollmentId = $res1->json('data.id');

        // AT-27: 2nd enrollment in same period is blocked
        $res2 = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionB->id,
            ]);
        $res2->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_ENROLLED_IN_PERIOD');

        // AT-24: Cancel 1st enrollment
        $cancelRes = $this->actingAs($this->staffUser)
            ->postJson("/api/v1/admissions/enrollments/{$enrollmentId}/cancel", [
                'reason' => 'Student decided to take a break',
            ]);
        $cancelRes->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        // AT-28: After cancellation, student CAN be enrolled again in that period!
        $resReEnroll = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);
        $resReEnroll->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_BILLING');
    }

    /**
     * Test AT-30 to AT-34: Returning Student & Re-Admission Flow
     */
    public function test_returning_student_re_admission_flow(): void
    {
        // Setup initial enrollment
        $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);

        // AT-30: Returning context returns previous enrollment
        $ctxRes = $this->actingAs($this->staffUser)
            ->getJson("/api/v1/admissions/students/{$this->activeStudent->id}/returning-context");
        $ctxRes->assertStatus(200)
            ->assertJsonPath('data.student.id', $this->activeStudent->id)
            ->assertJsonPath('data.previous_enrollment.period.id', $this->calendarPeriod->id);

        // Setup 2027 period for continuation
        $period2027 = AcademicPeriod::create([
            'program_id' => $this->calendarProgram->id,
            'label' => 'দাখিল ২০২৭',
            'calendar_year' => 2027,
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'state' => 'OPEN',
        ]);
        $class7 = ProgramClass::create([
            'program_id' => $this->calendarProgram->id,
            'code' => 'DKH-7',
            'name_bn' => 'দাখিল সপ্তম',
            'name_en' => 'Dakhil 7',
            'stage_number' => 2,
            'sort_order' => 2,
        ]);
        $offering2027 = AcademicOffering::create([
            'academic_period_id' => $period2027->id,
            'program_class_id' => $class7->id,
            'is_active' => true,
        ]);
        $section2027 = OfferingSection::create([
            'academic_offering_id' => $offering2027->id,
            'code' => 'SEC-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 35,
            'is_active' => true,
        ]);

        // AT-32: Submit continuing enrollment
        $retRes = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments/returning', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $period2027->id,
                'offering_section_id' => $section2027->id,
                'notes' => 'Progressing to Class 7',
            ]);
        $retRes->assertStatus(201)
            ->assertJsonPath('data.enrollment_type', 'CONTINUING')
            ->assertJsonPath('data.status', 'PENDING_BILLING');
    }

    /**
     * Test AT-35 to AT-38: Session Progression for Multi-Year Programs
     */
    public function test_session_progression_flow(): void
    {
        // Enroll student in Year 1 of Alim
        $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->sessionProgram->id,
                'academic_cohort_id' => $this->sessionCohort->id,
                'academic_period_id' => $this->sessionPeriodYear1->id,
                'offering_section_id' => $this->sessionSectionYear1->id,
            ]);

        // AT-35: Progression context returns timeline and next period
        $progCtx = $this->actingAs($this->staffUser)
            ->getJson("/api/v1/admissions/students/{$this->activeStudent->id}/session-progression");
        $progCtx->assertStatus(200)
            ->assertJsonPath('data.has_session_program', true)
            ->assertJsonPath('data.next_period.id', $this->sessionPeriodYear2->id);

        // Setup offering & section for Year 2
        $offeringYear2 = AcademicOffering::create([
            'academic_period_id' => $this->sessionPeriodYear2->id,
            'program_class_id' => $this->sessionClassYear2->id,
            'is_active' => true,
        ]);
        $sectionYear2 = OfferingSection::create([
            'academic_offering_id' => $offeringYear2->id,
            'code' => 'SEC-A',
            'name_bn' => 'শাখা ক',
            'name_en' => 'Section A',
            'capacity' => 40,
            'is_active' => true,
        ]);

        // AT-37: Submit session progression enrollment
        $progRes = $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments/session-progression', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->sessionProgram->id,
                'academic_cohort_id' => $this->sessionCohort->id,
                'academic_period_id' => $this->sessionPeriodYear2->id,
                'offering_section_id' => $sectionYear2->id,
                'notes' => 'Advancing to Alim 2nd Year',
            ]);
        $progRes->assertStatus(201)
            ->assertJsonPath('data.enrollment_type', 'CONTINUING')
            ->assertJsonPath('data.status', 'PENDING_BILLING');
    }

    /**
     * Test AT-39 to AT-41: Workbench Filtering, Metrics and Student Search
     */
    public function test_workbench_filtering_and_student_search(): void
    {
        $this->actingAs($this->staffUser)
            ->postJson('/api/v1/admissions/enrollments', [
                'student_record_id' => $this->activeStudent->id,
                'academic_program_id' => $this->calendarProgram->id,
                'academic_period_id' => $this->calendarPeriod->id,
                'offering_section_id' => $this->calendarSectionA->id,
            ]);

        // AT-39 & AT-40: Workbench index
        $wbRes = $this->actingAs($this->staffUser)
            ->getJson('/api/v1/admissions/workbench');
        $wbRes->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.pending_billing', 1);

        // AT-41: Student Search
        $searchRes = $this->actingAs($this->staffUser)
            ->getJson('/api/v1/admissions/search-students?query=Abdullah');
        $searchRes->assertStatus(200);
        $this->assertNotEmpty($searchRes->json('data'));
        $this->assertEquals($this->activeStudent->id, $searchRes->json('data.0.id'));
    }
}
