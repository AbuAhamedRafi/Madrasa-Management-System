<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentCreationService;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GuardianAndIdentifierTest extends TestCase
{
    use DatabaseTransactions;

    protected User $staff;
    protected Student $student;
    protected Guardian $primaryGuardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->staff = $this->createUserWithRole('m5.staff', 'OFFICE_STAFF');

        $creationService = app(StudentCreationService::class);
        $res = $creationService->create([
            'name_bn' => 'মুহাম্মদ উমর',
            'name_en' => 'Muhammad Umar',
            'date_of_birth' => '2016-02-14',
            'gender' => 'MALE',
            'admission_date' => '2026-01-20',
            'primary_guardian' => [
                'name_bn' => 'উমর পিতা',
                'name_en' => 'Umar Father',
                'phone' => '01755667788',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ], $this->staff->id);

        $this->student = Student::with('guardians')->find($res['data']['id']);
        $this->primaryGuardian = $this->student->guardians->first();
    }

    protected function createUserWithRole(string $username, string $roleKey): User
    {
        $user = User::create([
            'public_id' => 'usr_' . uniqid(),
            'name_bn' => 'টেস্ট স্টাফ',
            'name_en' => 'Test Staff',
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

    public function test_can_link_secondary_guardian(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/guardians", [
                'name_bn' => 'উমর মাতা',
                'name_en' => 'Umar Mother',
                'phone' => '01855667788',
                'relation' => 'MOTHER',
                'sms_consent' => false,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.name_en', 'Umar Mother')
            ->assertJsonPath('data.is_primary', false)
            ->assertJsonPath('data.sms_consent', false);

        $this->assertDatabaseHas('student_guardians', [
            'student_id' => $this->student->id,
            'relation' => 'MOTHER',
            'is_primary' => false,
        ]);
    }

    public function test_can_share_guardian_with_sibling_without_collision(): void
    {
        $creationService = app(StudentCreationService::class);
        $siblingRes = $creationService->create([
            'name_bn' => 'উমরের ভাই',
            'name_en' => 'Umars Brother',
            'date_of_birth' => '2018-05-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-22',
            'primary_guardian' => [
                'id' => $this->primaryGuardian->id, // Share the exact same father
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ], $this->staff->id);

        $siblingId = $siblingRes['data']['id'];
        $this->assertNotEquals($this->student->id, $siblingId);

        $this->assertDatabaseHas('student_guardians', [
            'student_id' => $siblingId,
            'guardian_id' => $this->primaryGuardian->id,
            'is_primary' => true,
        ]);
    }

    public function test_can_switch_primary_guardian(): void
    {
        // First add mother
        $motherRes = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/guardians", [
                'name_bn' => 'উমর মাতা',
                'name_en' => 'Umar Mother',
                'phone' => '01855667788',
                'relation' => 'MOTHER',
                'sms_consent' => false,
            ]);
        $motherId = $motherRes->json('data.id');

        // Make mother primary
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/guardians/{$motherId}/primary");

        $res->assertStatus(200)
            ->assertJsonPath('data.is_primary', true);

        // Verify father is no longer primary
        $this->assertDatabaseHas('student_guardians', [
            'student_id' => $this->student->id,
            'guardian_id' => $this->primaryGuardian->id,
            'is_primary' => false,
        ]);

        $this->assertDatabaseHas('student_guardians', [
            'student_id' => $this->student->id,
            'guardian_id' => $motherId,
            'is_primary' => true,
        ]);
    }

    public function test_cannot_unlink_primary_guardian(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->deleteJson("/api/v1/students/{$this->student->id}/guardians/{$this->primaryGuardian->id}");

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'PRIMARY_GUARDIAN_REQUIRED');
    }

    public function test_can_toggle_sms_consent(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->patchJson("/api/v1/students/{$this->student->id}/guardians/{$this->primaryGuardian->id}/consent", [
                'sms_consent' => false,
                'consent_source' => 'WRITTEN_OPT_OUT',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.sms_consent', false);

        $this->assertDatabaseHas('student_guardians', [
            'student_id' => $this->student->id,
            'guardian_id' => $this->primaryGuardian->id,
            'sms_consent' => false,
        ]);
    }

    public function test_can_add_and_reject_duplicate_external_identifier(): void
    {
        // Add BMEB registration
        $res1 = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/identifiers", [
                'namespace' => 'BMEB_REG',
                'value' => 'BMEB-2026-999',
                'issuer' => 'Bangladesh Madrasah Education Board',
            ]);

        $res1->assertStatus(201)
            ->assertJsonPath('data.value_normalized', 'BMEB-2026-999');

        // Attempt duplicate under same namespace -> 409
        $res2 = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/identifiers", [
                'namespace' => 'BMEB_REG',
                'value' => 'bmeb-2026-999', // Case insensitive check
            ]);

        $res2->assertStatus(409)
            ->assertJsonPath('error.code', 'STUDENT_IDENTIFIER_CONFLICT');
    }

    public function test_can_remove_identifier(): void
    {
        $resAdd = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/identifiers", [
                'namespace' => 'PREV_ROLL',
                'value' => 'ROLL-101',
            ]);
        $identId = $resAdd->json('data.id');

        $resDel = $this->actingAs($this->staff, 'sanctum')
            ->deleteJson("/api/v1/students/{$this->student->id}/identifiers/{$identId}");

        $resDel->assertStatus(200);

        $this->assertDatabaseMissing('student_identifiers', [
            'id' => $identId,
        ]);
    }
}

