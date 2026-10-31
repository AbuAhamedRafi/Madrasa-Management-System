<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Enums\StudentStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeliverableThreeComprehensiveTest extends TestCase
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

        $this->admin = $this->createUserWithRole('d03.admin', 'ADMIN');
        $this->staff = $this->createUserWithRole('d03.staff', 'OFFICE_STAFF');
        $this->accounts = $this->createUserWithRole('d03.accounts', 'ACCOUNTS');
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

    /**
     * AT-D03-01 & AT-D03-02: Permanent Student ID format (YY01/YY02 + seq), rejected if supplied by client.
     */
    public function test_student_id_format_and_immutable_allocation(): void
    {
        // 1. Client supplied student_id fails
        $payloadWithId = [
            'student_id' => '2601999',
            'name_bn' => 'আব্দুর রহমান',
            'name_en' => 'Abdur Rahman',
            'date_of_birth' => '2015-05-12',
            'gender' => 'MALE',
            'admission_date' => '2026-03-10', // month 3 -> prefix 2601
            'primary_guardian' => [
                'name_bn' => 'রফিকুল ইসলাম',
                'name_en' => 'Rafiqul Islam',
                'phone' => '01711000001',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ];

        $res1 = $this->actingAs($this->staff)->postJson('/api/v1/students', $payloadWithId);
        $res1->assertStatus(422);

        // 2. Correct allocation with prefix 2601 (admission in March)
        unset($payloadWithId['student_id']);
        $res2 = $this->actingAs($this->staff)->postJson('/api/v1/students', $payloadWithId);
        $res2->assertStatus(201);
        $studentId1 = $res2->json('data.student_id');
        $this->assertStringStartsWith('2601', $studentId1);

        // 3. Correct allocation with prefix 2602 (admission in August)
        $payloadSecondHalf = $payloadWithId;
        $payloadSecondHalf['name_en'] = 'Abdur Rahim';
        $payloadSecondHalf['name_bn'] = 'আব্দুর রহিম';
        $payloadSecondHalf['admission_date'] = '2026-08-15'; // month 8 -> prefix 2602
        $payloadSecondHalf['primary_guardian']['phone'] = '01711000002';

        $res3 = $this->actingAs($this->staff)->postJson('/api/v1/students', $payloadSecondHalf);
        $res3->assertStatus(201);
        $studentId2 = $res3->json('data.student_id');
        $this->assertStringStartsWith('2602', $studentId2);
    }

    /**
     * AT-D03-06 & AT-D03-08: Exact BRC duplicate check blocks; sibling phone allows registration.
     */
    public function test_exact_brc_blocks_and_sibling_phone_allowed(): void
    {
        $brc = '20151234567890123';
        $sharedGuardianPhone = '01711000088';

        // Student 1
        $payload1 = [
            'name_bn' => 'হাসান মাহমুদ',
            'name_en' => 'Hasan Mahmud',
            'date_of_birth' => '2014-06-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'birth_registration_number' => $brc,
            'primary_guardian' => [
                'name_bn' => 'আবুল কালাম',
                'name_en' => 'Abul Kalam',
                'phone' => $sharedGuardianPhone,
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ];

        $res1 = $this->actingAs($this->staff)->postJson('/api/v1/students', $payload1);
        $res1->assertStatus(201);

        // Student 2 with SAME BRC -> Must be blocked (409)
        $payload2 = $payload1;
        $payload2['name_en'] = 'Different Name';
        $res2 = $this->actingAs($this->staff)->postJson('/api/v1/students', $payload2);
        $res2->assertStatus(409);

        // Sibling with SAME guardian phone, but different name and different DOB -> Must succeed
        $payloadSibling = [
            'name_bn' => 'হুসাইন মাহমুদ',
            'name_en' => 'Hussain Mahmud',
            'date_of_birth' => '2016-09-20',
            'gender' => 'MALE',
            'admission_date' => '2026-01-10',
            'birth_registration_number' => '20169876543210987',
            'primary_guardian' => [
                'name_bn' => 'আবুল কালাম',
                'name_en' => 'Abul Kalam',
                'phone' => $sharedGuardianPhone,
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ];

        $resSibling = $this->actingAs($this->staff)->postJson('/api/v1/students', $payloadSibling);
        $resSibling->assertStatus(201);
    }

    /**
     * AT-D03-09 & AT-D03-10: Preflight check & duplicate review override workflow.
     */
    public function test_preflight_duplicate_check_and_override_review_queue(): void
    {
        // 1. Preflight check endpoint
        $checkPayload = [
            'name_bn' => 'হাসান মাহমুদ',
            'name_en' => 'Hasan Mahmud',
            'date_of_birth' => '2015-05-12',
            'guardian_phone' => '01711000001',
        ];

        $resCheck = $this->actingAs($this->staff)->postJson('/api/v1/students/check-duplicate', $checkPayload);
        $resCheck->assertStatus(200);
        $resCheck->assertJsonStructure(['data' => ['exact_match', 'is_blocked', 'matches']]);

        // 2. Submit ticket to review queue
        $ticketPayload = [
            'proposed_payload' => [
                'name_bn' => 'মাহমুদুল হাসান',
                'name_en' => 'Mahmudul Hasan',
                'date_of_birth' => '2015-05-12',
                'gender' => 'MALE',
                'admission_date' => '2026-02-01',
                'primary_guardian' => [
                    'name_bn' => 'আবুল কালাম',
                    'name_en' => 'Abul Kalam',
                    'phone' => '01711000099',
                    'relation' => 'FATHER',
                    'sms_consent' => true,
                ],
            ],
            'candidate_matches' => [
                ['match_reasons' => ['DOB and Phonetic Name match']],
            ],
        ];

        $resTicket = $this->actingAs($this->staff)->postJson('/api/v1/students/duplicate-reviews', $ticketPayload);
        $resTicket->assertStatus(201);
        $ticketId = $resTicket->json('data.id');

        // 3. Admin views review queue
        $resQueue = $this->actingAs($this->admin)->getJson('/api/v1/admin/student-duplicates?status=PENDING');
        $resQueue->assertStatus(200);

        // 4. Admin approves override with reason
        $resDecision = $this->actingAs($this->admin)->postJson("/api/v1/admin/student-duplicates/{$ticketId}/decision", [
            'decision' => 'APPROVE',
            'reason' => 'Twin sibling verified by NID verification.',
        ]);
        $resDecision->assertStatus(200);
        $this->assertEquals('APPROVED', $resDecision->json('data.status'));

        // 5. Staff registers student referencing override ticket
        $finalPayload = $ticketPayload['proposed_payload'];
        $finalPayload['override_review_id'] = $ticketId;
        $resCreate = $this->actingAs($this->staff)->postJson('/api/v1/students', $finalPayload);
        $resCreate->assertStatus(201);
    }

    /**
     * AT-D03-11, AT-D03-12, AT-D03-13: Guardian relations, primary switch, unlink restrictions.
     */
    public function test_guardian_lifecycle_and_primary_switch(): void
    {
        // Create student
        $res = $this->actingAs($this->staff)->postJson('/api/v1/students', [
            'name_bn' => 'সালমান খান',
            'name_en' => 'Salman Khan',
            'date_of_birth' => '2016-04-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-15',
            'primary_guardian' => [
                'name_bn' => 'আজিজুল হক',
                'name_en' => 'Azizul Haque',
                'phone' => '01711223344',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ]);
        $res->assertStatus(201);
        $studentId = $res->json('data.id');
        $primaryGuardianId = $res->json('data.primary_guardian.id');

        // Add secondary guardian (Mother)
        $resAddSec = $this->actingAs($this->staff)->postJson("/api/v1/students/{$studentId}/guardians", [
            'name_bn' => 'নাজনীন আক্তার',
            'name_en' => 'Nazneen Akhter',
            'phone' => '01711223355',
            'relation' => 'MOTHER',
            'sms_consent' => false,
        ]);
        $resAddSec->assertStatus(201);
        $secondaryGuardianId = $resAddSec->json('data.id');

        // Attempting to delete primary guardian must fail
        $resDelPrim = $this->actingAs($this->staff)->deleteJson("/api/v1/students/{$studentId}/guardians/{$primaryGuardianId}");
        $resDelPrim->assertStatus(422);

        // Switch secondary to primary
        $resSwitch = $this->actingAs($this->staff)->postJson("/api/v1/students/{$studentId}/guardians/{$secondaryGuardianId}/primary");
        $resSwitch->assertStatus(200);

        // Verify primary changed in student profile
        $resProfile = $this->actingAs($this->staff)->getJson("/api/v1/students/{$studentId}");
        $this->assertEquals($secondaryGuardianId, $resProfile->json('data.primary_guardian.id'));

        // Toggle SMS consent
        $resConsent = $this->actingAs($this->staff)->patchJson("/api/v1/students/{$studentId}/guardians/{$secondaryGuardianId}/consent", [
            'sms_consent' => true,
            'consent_source' => 'PHONE_REQUEST',
        ]);
        $resConsent->assertStatus(200);
        $this->assertTrue($resConsent->json('data.sms_consent'));

        // Unlink former primary (now secondary) succeeds
        $resDelFormer = $this->actingAs($this->staff)->deleteJson("/api/v1/students/{$studentId}/guardians/{$primaryGuardianId}");
        $resDelFormer->assertStatus(200);
    }

    /**
     * AT-D03-16, AT-D03-17, AT-D03-18: Student external identifiers namespace uniqueness & removal.
     */
    public function test_student_identifiers_and_uniqueness(): void
    {
        $res = $this->actingAs($this->staff)->postJson('/api/v1/students', [
            'name_bn' => 'মুহাম্মদ ইসমাইল',
            'name_en' => 'Muhammad Ismail',
            'date_of_birth' => '2015-08-12',
            'gender' => 'MALE',
            'admission_date' => '2026-02-10',
            'primary_guardian' => [
                'name_bn' => 'আনোয়ারুল ইসলাম',
                'name_en' => 'Anwarul Islam',
                'phone' => '01711334455',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ]);
        $studentId = $res->json('data.id');

        // Add identifier
        $resId = $this->actingAs($this->staff)->postJson("/api/v1/students/{$studentId}/identifiers", [
            'namespace' => 'MADRASAH_BOARD',
            'value' => 'MB-2026-888',
            'issuer' => 'BMEB',
        ]);
        $resId->assertStatus(201);
        $identId = $resId->json('data.id');

        // Duplicate identifier in same namespace fails with 409 Conflict
        $resDupId = $this->actingAs($this->staff)->postJson("/api/v1/students/{$studentId}/identifiers", [
            'namespace' => 'MADRASAH_BOARD',
            'value' => 'MB-2026-888',
        ]);
        $resDupId->assertStatus(409);

        // Delete identifier
        $resDelId = $this->actingAs($this->staff)->deleteJson("/api/v1/students/{$studentId}/identifiers/{$identId}");
        $resDelId->assertStatus(200);
    }

    /**
     * AT-D03-21 & AT-D03-22: Accounts role restricted financial lookup and profile blocking.
     */
    public function test_accounts_role_restricted_lookup_and_blocking(): void
    {
        $res = $this->actingAs($this->staff)->postJson('/api/v1/students', [
            'name_bn' => 'মুস্তাকিম আহমেদ',
            'name_en' => 'Mustakim Ahmed',
            'date_of_birth' => '2016-01-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-20',
            'primary_guardian' => [
                'name_bn' => 'মোজাম্মেল হক',
                'name_en' => 'Mozammel Haque',
                'phone' => '01711445566',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ]);
        $studentId = $res->json('data.id');
        $permanentId = $res->json('data.student_id');

        // Accounts lookup returns minimal data
        $resLookup = $this->actingAs($this->accounts)->getJson("/api/v1/students/lookup?query={$permanentId}");
        $resLookup->assertStatus(200);
        $item = $resLookup->json('data.0');
        $this->assertEquals($studentId, $item['id']);
        $this->assertArrayHasKey('student_id', $item);
        $this->assertArrayHasKey('name_bn', $item);
        $this->assertArrayHasKey('name_en', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayNotHasKey('date_of_birth', $item);
        $this->assertArrayNotHasKey('primary_guardian', $item);

        // Accounts accessing full profile receives 403 Forbidden
        $resProfile = $this->actingAs($this->accounts)->getJson("/api/v1/students/{$studentId}");
        $resProfile->assertStatus(403);
    }

    /**
     * AT-D03-23, AT-D03-24, AT-D03-25: Status lifecycle (REGISTERED -> ACTIVE with reason).
     */
    public function test_status_lifecycle_and_reason_validation(): void
    {
        $res = $this->actingAs($this->staff)->postJson('/api/v1/students', [
            'name_bn' => 'আব্দুল্লাহ আল নোমান',
            'name_en' => 'Abdullah Al Noman',
            'date_of_birth' => '2015-11-12',
            'gender' => 'MALE',
            'admission_date' => '2026-01-25',
            'primary_guardian' => [
                'name_bn' => 'মোকাদ্দেস আলী',
                'name_en' => 'Mokaddes Ali',
                'phone' => '01711556677',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ]);
        $studentId = $res->json('data.id');
        $this->assertEquals(StudentStatus::REGISTERED->value, $res->json('data.status'));

        // Transition without reason fails
        $resFail = $this->actingAs($this->admin)->patchJson("/api/v1/students/{$studentId}/status", [
            'status' => 'ACTIVE',
            'reason' => '',
        ]);
        $resFail->assertStatus(422);

        // Valid transition with reason
        $resActive = $this->actingAs($this->admin)->patchJson("/api/v1/students/{$studentId}/status", [
            'status' => 'ACTIVE',
            'reason' => 'Admission fee verified and verified documents.',
        ]);
        $resActive->assertStatus(200);
        $this->assertEquals(StudentStatus::ACTIVE->value, $resActive->json('data.status'));
    }

    /**
     * AT-D03-26, AT-D03-27, AT-D03-28, AT-D03-29, AT-D03-30, AT-D03-31:
     * Private document upload, magic-byte inspection, streaming, verification, archiving.
     */
    public function test_document_security_and_lifecycle(): void
    {
        $res = $this->actingAs($this->staff)->postJson('/api/v1/students', [
            'name_bn' => 'মোর্শেদ জাহান',
            'name_en' => 'Morshed Jahan',
            'date_of_birth' => '2015-03-22',
            'gender' => 'MALE',
            'admission_date' => '2026-02-14',
            'primary_guardian' => [
                'name_bn' => 'জাহাঙ্গীর আলম',
                'name_en' => 'Jahangir Alam',
                'phone' => '01711667788',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ]);
        $studentId = $res->json('data.id');

        // Valid PDF file upload with genuine PDF magic bytes
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $pdfFile = UploadedFile::fake()->createWithContent('birth_cert.pdf', $pdfContent);
        $resUpload = $this->actingAs($this->staff)->post("/api/v1/students/{$studentId}/documents", [
            'file' => $pdfFile,
            'type' => 'BIRTH_CERTIFICATE',
            'reason' => 'Official government birth certificate',
        ]);
        $resUpload->assertStatus(201);
        $docId = $resUpload->json('data.id');

        // Verify document
        $resVerify = $this->actingAs($this->staff)->postJson("/api/v1/students/{$studentId}/documents/{$docId}/verify", [
            'verification_status' => 'VERIFIED',
            'reason' => 'Original birth registration seal inspected.',
        ]);
        $resVerify->assertStatus(200);
        $this->assertEquals('VERIFIED', $resVerify->json('data.verification_status'));

        // Authenticated binary download stream
        $resDownload = $this->actingAs($this->staff)->get("/api/v1/students/{$studentId}/documents/{$docId}/download");
        $resDownload->assertStatus(200);
        $resDownload->assertHeader('Content-Disposition');

        // Archive document
        $resArchive = $this->actingAs($this->staff)->deleteJson("/api/v1/students/{$studentId}/documents/{$docId}", [
            'reason' => 'Updated version provided.',
        ]);
        $resArchive->assertStatus(200);
    }

    /**
     * AT-D03-32 & AT-D03-33: Idempotent registration handling.
     */
    public function test_idempotent_registration_and_mismatch(): void
    {
        $idempotencyKey = 'idemp_key_' . uniqid();
        $payload = [
            'name_bn' => 'রাকিবুল হাসান',
            'name_en' => 'Rakibul Hasan',
            'date_of_birth' => '2016-02-18',
            'gender' => 'MALE',
            'admission_date' => '2026-03-01',
            'primary_guardian' => [
                'name_bn' => 'হাবিবুর রহমান',
                'name_en' => 'Habibur Rahman',
                'phone' => '01711778899',
                'relation' => 'FATHER',
                'sms_consent' => true,
            ],
        ];

        // First attempt -> 201 Created
        $res1 = $this->actingAs($this->staff)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/students', $payload);
        $res1->assertStatus(201);
        $createdId = $res1->json('data.id');

        // Replay identical attempt -> Returns 200 with Idempotent-Replay header
        $res2 = $this->actingAs($this->staff)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/students', $payload);
        $res2->assertStatus(200);
        $res2->assertHeader('Idempotent-Replay', 'true');
        $this->assertEquals($createdId, $res2->json('data.id'));

        // Replay same key with altered payload -> 422 IDEMPOTENCY_PAYLOAD_MISMATCH
        $alteredPayload = $payload;
        $alteredPayload['name_en'] = 'Altered Name';
        $res3 = $this->actingAs($this->staff)->withHeader('Idempotency-Key', $idempotencyKey)->postJson('/api/v1/students', $alteredPayload);
        $res3->assertStatus(422);
    }

    /**
     * AT-D03-37 & AT-D03-38: Architecture boundaries & single-responsibility migrations.
     */
    public function test_deliverable_boundary_and_single_table_migrations(): void
    {
        // 1. D03 tables exist
        $this->assertTrue(Schema::hasTable('students'));
        $this->assertTrue(Schema::hasTable('guardians'));
        $this->assertTrue(Schema::hasTable('student_guardians'));
        $this->assertTrue(Schema::hasTable('student_identifiers'));
        $this->assertTrue(Schema::hasTable('student_documents'));
        $this->assertTrue(Schema::hasTable('student_duplicate_reviews'));
        $this->assertTrue(Schema::hasTable('student_id_sequences'));

        // 2. Future deliverable tables (D04 enrollments, D06 fees, D07 payments) do NOT exist yet
        $this->assertFalse(Schema::hasTable('student_enrollments'));
        $this->assertFalse(Schema::hasTable('section_assignments'));
        $this->assertFalse(Schema::hasTable('fee_liabilities'));
        $this->assertFalse(Schema::hasTable('invoices'));
        $this->assertFalse(Schema::hasTable('cash_slips'));

        // 3. Verify single-table migrations rule: No migration file contains multiple Schema::create calls
        $migrationFiles = glob(database_path('migrations/*.php'));
        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            $createCount = preg_match_all('/Schema::create\s*\(/', $content, $matches);
            $this->assertLessThanOrEqual(
                1,
                $createCount,
                "Migration file {$file} violates single-responsibility standard by creating {$createCount} tables."
            );
        }
    }
}
