<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDuplicateReview;
use App\Models\User;
use App\Services\Students\StudentCreationService;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DuplicateDetectionAndReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $staff;
    protected Student $existingStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->admin = $this->createUserWithRole('m6.admin', 'ADMIN');
        $this->staff = $this->createUserWithRole('m6.staff', 'OFFICE_STAFF');

        $creationService = app(StudentCreationService::class);
        $res = $creationService->create([
            'name_bn' => 'মুহাম্মদ মাহমুদ',
            'name_en' => 'Muhammad Mahmud',
            'date_of_birth' => '2015-07-20',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'birth_registration_number' => '20158888888888888',
            'primary_guardian' => [
                'name_bn' => 'মাহমুদ পিতা',
                'name_en' => 'Mahmud Father',
                'phone' => '01799887766',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ], $this->staff->id);

        $this->existingStudent = Student::find($res['data']['id']);
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

    public function test_exact_brc_match_returns_hard_block(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students/check-duplicate', [
                'birth_registration_number' => '20158888888888888',
                'name_en' => 'Different Name',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.exact_match', true)
            ->assertJsonPath('data.is_blocked', true);
    }

    public function test_fuzzy_match_flags_potential_duplicate(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students/check-duplicate', [
                'name_en' => 'Muhammad Mahmud',
                'date_of_birth' => '2015-07-20',
                'guardian_phone' => '01799887766',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.exact_match', false)
            ->assertJsonPath('data.has_potential_duplicate', true)
            ->assertJsonPath('data.requires_review', true);
    }

    public function test_sibling_sharing_guardian_phone_is_not_flagged_as_duplicate(): void
    {
        $res = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students/check-duplicate', [
                'name_en' => 'Zayd Brother',
                'date_of_birth' => '2019-11-05',
                'guardian_phone' => '01799887766', // Same father's phone
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.exact_match', false)
            ->assertJsonPath('data.has_potential_duplicate', false)
            ->assertJsonPath('data.requires_review', false);
    }

    public function test_duplicate_review_workflow_submission_and_approval(): void
    {
        $payload = [
            'name_bn' => 'মুহাম্মদ মাহমুদ ২',
            'name_en' => 'Muhammad Mahmud Two',
            'date_of_birth' => '2015-07-20',
            'gender' => 'MALE',
            'admission_date' => '2026-02-01',
            'primary_guardian' => [
                'name_bn' => 'পিতা',
                'name_en' => 'Father',
                'phone' => '01799887766',
                'relation' => 'FATHER',
            ],
        ];

        // 1. Staff submits for review
        $resSubmit = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students/duplicate-reviews', [
                'proposed_payload' => $payload,
                'candidate_matches' => [
                    ['student_id' => $this->existingStudent->student_id, 'reason' => 'Similar Name & DOB'],
                ],
            ]);

        $resSubmit->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING');
        $reviewId = $resSubmit->json('data.id');

        // 2. Staff cannot decide review (403)
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/admin/student-duplicates/{$reviewId}/decision", [
                'decision' => 'APPROVE',
                'reason' => 'Staff trying to approve',
            ])
            ->assertStatus(403);

        // 3. Admin decides review with approval reason
        $resApprove = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/student-duplicates/{$reviewId}/decision", [
                'decision' => 'APPROVE',
                'reason' => 'Verified with birth certificate: distinct individual, different village.',
            ]);

        $resApprove->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');

        // 4. Staff registers student with approved override_review_id
        $payload['override_review_id'] = $reviewId;
        $resCreate = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/students', $payload);

        $resCreate->assertStatus(201);
        $newStudentId = $resCreate->json('data.id');

        $this->assertDatabaseHas('student_duplicate_reviews', [
            'id' => $reviewId,
            'status' => 'APPROVED',
            'student_id' => $newStudentId,
        ]);
    }
}

