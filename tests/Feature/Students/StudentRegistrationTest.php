<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $staff;
    protected User $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->admin = $this->createUserWithRole('m3.admin', 'ADMIN');
        $this->staff = $this->createUserWithRole('m3.staff', 'OFFICE_STAFF');
        $this->accounts = $this->createUserWithRole('m3.accounts', 'ACCOUNTS');
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

    public function test_staff_can_register_student_with_primary_guardian(): void
    {
        $payload = [
            'name_bn' => 'আব্দুর রহমান',
            'name_en' => 'Abdur Rahman',
            'date_of_birth' => '2015-05-12',
            'gender' => 'MALE',
            'admission_date' => '2026-02-10',
            'birth_registration_number' => '20151234567890123',
            'present_address_bn' => 'ঢাকা, বাংলাদেশ',
            'primary_guardian' => [
                'name_bn' => 'মুহাম্মদ আলী',
                'name_en' => 'Muhammad Ali',
                'phone' => '01711223344',
                'relation' => 'FATHER',
                'sms_consent' => true,
                'consent_source' => 'ADMISSION_FORM',
            ],
        ];

        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('data.name_bn', 'আব্দুর রহমান')
            ->assertJsonPath('data.status', 'REGISTERED')
            ->assertJsonPath('data.primary_guardian.phone', '01711223344')
            ->assertJsonPath('data.primary_guardian.is_primary', true)
            ->assertJsonPath('data.primary_guardian.sms_consent', true);

        $studentId = $res->json('data.student_id');
        $this->assertNotEmpty($studentId);
        // In February 2026 (month <= 6), prefix must start with 2601
        $this->assertStringStartsWith('2601', $studentId);

        $this->assertDatabaseHas('students', [
            'student_id' => $studentId,
            'name_en' => 'Abdur Rahman',
            'status' => 'REGISTERED',
        ]);
    }

    public function test_student_registered_in_second_half_has_02_term_prefix(): void
    {
        $payload = [
            'name_bn' => 'ফাতিমা খাতুন',
            'name_en' => 'Fatima Khatun',
            'date_of_birth' => '2016-08-20',
            'gender' => 'FEMALE',
            'admission_date' => '2026-09-15',
            'primary_guardian' => [
                'name_bn' => 'আব্দুল করিম',
                'name_en' => 'Abdul Karim',
                'phone' => '01811223344',
                'relation' => 'FATHER',
            ],
        ];

        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', $payload);

        $res->assertStatus(201);
        $studentId = $res->json('data.student_id');
        // In September 2026 (month > 6), prefix must start with 2602
        $this->assertStringStartsWith('2602', $studentId);
    }

    public function test_rejects_manual_student_id_or_status_input(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', [
                'student_id' => '202699999',
                'name_bn' => 'টেস্ট শিক্ষার্থী',
                'name_en' => 'Test Student',
                'date_of_birth' => '2015-01-01',
                'gender' => 'MALE',
                'primary_guardian' => [
                    'name_bn' => 'গার্ডিয়ান',
                    'name_en' => 'Guardian',
                    'phone' => '01700000000',
                    'relation' => 'FATHER',
                ],
            ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);

        $resStatus = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', [
                'status' => 'ACTIVE',
                'name_bn' => 'টেস্ট শিক্ষার্থী',
                'name_en' => 'Test Student',
                'date_of_birth' => '2015-01-01',
                'gender' => 'MALE',
                'primary_guardian' => [
                    'name_bn' => 'গার্ডিয়ান',
                    'name_en' => 'Guardian',
                    'phone' => '01700000000',
                    'relation' => 'FATHER',
                ],
            ]);

        $resStatus->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_rejects_student_creation_without_primary_guardian(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', [
                'name_bn' => 'টেস্ট শিক্ষার্থী',
                'name_en' => 'Test Student',
                'date_of_birth' => '2015-01-01',
                'gender' => 'MALE',
            ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['primary_guardian']);
    }

    public function test_idempotent_admission_creation_and_payload_mismatch(): void
    {
        $key = 'idem_key_' . uniqid();
        $payload = [
            'name_bn' => 'হাসান মাহমুদ',
            'name_en' => 'Hasan Mahmud',
            'date_of_birth' => '2014-04-05',
            'gender' => 'MALE',
            'admission_date' => '2026-03-01',
            'primary_guardian' => [
                'name_bn' => 'মাহমুদ আলম',
                'name_en' => 'Mahmud Alam',
                'phone' => '01911223344',
                'relation' => 'FATHER',
            ],
        ];

        // First attempt -> 201 Created
        $res1 = $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/students', $payload);

        $res1->assertStatus(201);
        $studentId = $res1->json('data.student_id');

        // Retry with exact same payload -> 200 OK replay with Idempotent-Replay header
        $res2 = $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/students', $payload);

        $res2->assertStatus(200)
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.student_id', $studentId);

        // Retry with same key but different payload -> 422 IDEMPOTENCY_PAYLOAD_MISMATCH
        $modifiedPayload = $payload;
        $modifiedPayload['name_en'] = 'Different Name';

        $res3 = $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/students', $modifiedPayload);

        $res3->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_exact_duplicate_birth_registration_is_blocked(): void
    {
        $brc = '20159876543210987';
        $payload1 = [
            'name_bn' => 'আব্দুল্লাহ',
            'name_en' => 'Abdullah',
            'date_of_birth' => '2015-06-15',
            'gender' => 'MALE',
            'birth_registration_number' => $brc,
            'primary_guardian' => [
                'name_bn' => 'গার্ডিয়ান ১',
                'name_en' => 'Guardian 1',
                'phone' => '01711000001',
                'relation' => 'FATHER',
            ],
        ];

        $res1 = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', $payload1);
        $res1->assertStatus(201);

        $payload2 = [
            'name_bn' => 'আব্দুল্লাহ ২',
            'name_en' => 'Abdullah Two',
            'date_of_birth' => '2015-06-15',
            'gender' => 'MALE',
            'birth_registration_number' => $brc,
            'primary_guardian' => [
                'name_bn' => 'গার্ডিয়ান ২',
                'name_en' => 'Guardian 2',
                'phone' => '01711000002',
                'relation' => 'FATHER',
            ],
        ];

        $res2 = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', $payload2);

        $res2->assertStatus(409)
            ->assertJsonPath('error.code', 'STUDENT_IDENTIFIER_CONFLICT');
    }

    public function test_accounts_cannot_create_students(): void
    {
        $res = $this->actingAs($this->accounts, 'sanctum')
            ->postJson('/api/v1/students', [
                'name_bn' => 'নাম',
                'name_en' => 'Name',
                'date_of_birth' => '2015-01-01',
                'gender' => 'MALE',
                'primary_guardian' => [
                    'name_bn' => 'গার্ডিয়ান',
                    'name_en' => 'Guardian',
                    'phone' => '01700000000',
                    'relation' => 'FATHER',
                ],
            ]);

        $res->assertStatus(403);
    }
}

