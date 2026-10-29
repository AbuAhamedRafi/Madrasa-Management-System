<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Enums\StudentStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentCreationService;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudentDirectoryAndProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $staff;
    protected User $accounts;
    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->admin = $this->createUserWithRole('m4.admin', 'ADMIN');
        $this->staff = $this->createUserWithRole('m4.staff', 'OFFICE_STAFF');
        $this->accounts = $this->createUserWithRole('m4.accounts', 'ACCOUNTS');

        $creationService = app(StudentCreationService::class);
        $res = $creationService->create([
            'name_bn' => 'মুহাম্মদ ইব্রাহিম',
            'name_en' => 'Muhammad Ibrahim',
            'date_of_birth' => '2015-04-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-15',
            'birth_registration_number' => '20150000000000001',
            'present_address_bn' => 'ধানমন্ডি, ঢাকা',
            'primary_guardian' => [
                'name_bn' => 'ইব্রাহিম পিতা',
                'name_en' => 'Ibrahim Father',
                'phone' => '01719876543',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ], $this->staff->id);

        $this->student = Student::find($res['data']['id']);
    }

    protected function createUserWithRole(string $username, string $roleKey): User
    {
        $user = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'টেস্ট ইউজার',
            'name_en' => 'Test User',
            'username' => $username,
            'email' => $username . '@mms.test',
            'password' => bcrypt('TestPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);

        $role = Role::where('key', $roleKey)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_staff_can_paginate_and_search_students(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/students?query=' . urlencode('ইব্রাহিম'));

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name_en', 'Muhammad Ibrahim');

        // Search by permanent Student ID
        $resId = $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/students?query=' . $this->student->student_id);

        $resId->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student_id', $this->student->student_id);
    }

    public function test_staff_can_view_single_student_with_audit_history(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/students/' . $this->student->id);

        $res->assertStatus(200)
            ->assertJsonPath('data.student_id', $this->student->student_id)
            ->assertJsonPath('data.birth_registration_number', '20150000000000001')
            ->assertJsonPath('data.primary_guardian.phone', '01719876543')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'student_id',
                    'name_bn',
                    'name_en',
                    'audit_history',
                ],
            ]);
    }

    public function test_staff_can_update_profile_but_student_id_is_immutable(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->patchJson('/api/v1/students/' . $this->student->id, [
                'name_en' => 'Muhammad Ibrahim Updated',
                'present_address_en' => 'Dhanmondi, Dhaka',
                'reason' => 'Corrected spelling of address',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.name_en', 'Muhammad Ibrahim Updated')
            ->assertJsonPath('data.present_address_en', 'Dhanmondi, Dhaka');

        // Immutable student_id check
        $resImmutable = $this->actingAs($this->staff, 'sanctum')
            ->patchJson('/api/v1/students/' . $this->student->id, [
                'student_id' => '999999999',
            ]);

        $resImmutable->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_status_lifecycle_transition_and_audit(): void
    {
        // Transition from REGISTERED to ACTIVE requires reason and students.manage_status permission (admin)
        $res = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/students/' . $this->student->id . '/status', [
                'status' => 'ACTIVE',
                'reason' => 'Fee settlement verified and admission approved.',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('students', [
            'id' => $this->student->id,
            'status' => 'ACTIVE',
        ]);

        // Attempt invalid status transition without reason -> 422
        $resNoReason = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/students/' . $this->student->id . '/status', [
                'status' => 'INACTIVE',
                'reason' => '',
            ]);

        $resNoReason->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_accounts_financial_lookup_is_strictly_minimized(): void
    {
        // Accounts can access /lookup
        $resLookup = $this->actingAs($this->accounts, 'sanctum')
            ->getJson('/api/v1/students/lookup?query=' . $this->student->student_id);

        $resLookup->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student_id', $this->student->student_id)
            ->assertJsonPath('data.0.name_bn', 'মুহাম্মদ ইব্রাহিম')
            ->assertJsonPath('data.0.status', 'REGISTERED');

        // Verify Accounts does NOT receive PII or guardian info
        $item = $resLookup->json('data.0');
        $this->assertArrayNotHasKey('date_of_birth', $item);
        $this->assertArrayNotHasKey('birth_registration_number', $item);
        $this->assertArrayNotHasKey('primary_guardian', $item);
        $this->assertArrayNotHasKey('guardians', $item);
        $this->assertArrayNotHasKey('present_address_bn', $item);
        $this->assertArrayNotHasKey('documents', $item);

        // Accounts is strictly forbidden from full directory and profile CRUD (AT-D03-13)
        $this->actingAs($this->accounts, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertStatus(403);

        $this->actingAs($this->accounts, 'sanctum')
            ->getJson('/api/v1/students/' . $this->student->id)
            ->assertStatus(403);

        $this->actingAs($this->accounts, 'sanctum')
            ->patchJson('/api/v1/students/' . $this->student->id, ['name_en' => 'Hacked'])
            ->assertStatus(403);
    }
}

