<?php

namespace Tests\Feature\Students;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Students\StudentCreationService;
use Database\Seeders\AcademicFoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentDocumentTest extends TestCase
{
    use DatabaseTransactions;

    protected User $staff;
    protected User $admin;
    protected User $accounts;
    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AcademicFoundationSeeder::class);

        $this->staff = $this->createUserWithRole('m7.staff', 'OFFICE_STAFF');
        $this->admin = $this->createUserWithRole('m7.admin', 'ADMIN');
        $this->accounts = $this->createUserWithRole('m7.accounts', 'ACCOUNTS');

        $creationService = app(StudentCreationService::class);
        $res = $creationService->create([
            'name_bn' => 'মুহাম্মদ ইউসুফ',
            'name_en' => 'Muhammad Yusuf',
            'date_of_birth' => '2016-09-01',
            'gender' => 'MALE',
            'admission_date' => '2026-02-15',
            'primary_guardian' => [
                'name_bn' => 'ইউসুফ পিতা',
                'name_en' => 'Yusuf Father',
                'phone' => '01712345678',
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

    public function test_can_upload_valid_pdf_to_private_storage(): void
    {
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('birth_cert.pdf', $pdfContent);

        $res = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $file,
                'type' => 'BIRTH_CERTIFICATE',
                'reason' => 'Official birth certificate copy',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.file_name', 'birth_cert.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.verification_status', 'PENDING');

        // Verify storage_key and internal paths are NOT exposed
        $data = $res->json('data');
        $this->assertArrayNotHasKey('storage_key', $data);
        $this->assertArrayNotHasKey('full_path', $data);
    }

    public function test_rejects_unsafe_executable_and_polyglot_files(): void
    {
        // Malicious file pretending to be PDF
        $fakePdf = UploadedFile::fake()->createWithContent('exploit.pdf', '<?php phpinfo(); ?>');

        $res = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $fakePdf,
                'type' => 'OTHER',
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSAFE_DOCUMENT_TYPE');

        // Executable script extension
        $exe = UploadedFile::fake()->create('script.sh', 100, 'application/x-sh');
        $resExe = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $exe,
                'type' => 'OTHER',
            ]);

        $resExe->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSAFE_DOCUMENT_TYPE');
    }

    public function test_streaming_download_and_audit(): void
    {
        $pdfContent = "%PDF-1.4 sample pdf binary data for test %%EOF";
        $file = UploadedFile::fake()->createWithContent('transcript.pdf', $pdfContent);

        $uploadRes = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $file,
                'type' => 'PREVIOUS_TRANSCRIPT',
            ]);
        $docId = $uploadRes->json('data.id');

        // Stream download
        $downloadRes = $this->actingAs($this->staff, 'sanctum')
            ->get("/api/v1/students/{$this->student->id}/documents/{$docId}/download");

        $downloadRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('audit_logs', [
            'action_code' => 'DOCUMENT_DOWNLOADED',
            'entity_type' => 'StudentDocument',
            'entity_id' => $docId,
        ]);
    }

    public function test_accounts_is_strictly_forbidden_from_document_access(): void
    {
        $pdfContent = "%PDF-1.4 sample %%EOF";
        $file = UploadedFile::fake()->createWithContent('nid.pdf', $pdfContent);

        $uploadRes = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $file,
                'type' => 'NID_GUARDIAN',
            ]);
        $docId = $uploadRes->json('data.id');

        // Accounts list documents -> 403
        $this->actingAs($this->accounts, 'sanctum')
            ->getJson("/api/v1/students/{$this->student->id}/documents")
            ->assertStatus(403);

        // Accounts download document -> 403
        $this->actingAs($this->accounts, 'sanctum')
            ->get("/api/v1/students/{$this->student->id}/documents/{$docId}/download")
            ->assertStatus(403);
    }

    public function test_admin_can_verify_and_archive_document(): void
    {
        $pdfContent = "%PDF-1.4 sample %%EOF";
        $file = UploadedFile::fake()->createWithContent('medical.pdf', $pdfContent);

        $uploadRes = $this->actingAs($this->staff, 'sanctum')
            ->post("/api/v1/students/{$this->student->id}/documents", [
                'file' => $file,
                'type' => 'MEDICAL',
            ]);
        $docId = $uploadRes->json('data.id');

        // Admin verifies document
        $verifyRes = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/students/{$this->student->id}/documents/{$docId}/verify", [
                'verification_status' => 'VERIFIED',
                'reason' => 'Official civil surgeon certificate verified.',
            ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('data.verification_status', 'VERIFIED');

        // Admin archives document
        $archiveRes = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/students/{$this->student->id}/documents/{$docId}", [
                'reason' => 'Superseded by updated medical report.',
            ]);

        $archiveRes->assertStatus(200);

        // Attempt download of archived document -> 410 Gone / Conflict
        $this->actingAs($this->staff, 'sanctum')
            ->get("/api/v1/students/{$this->student->id}/documents/{$docId}/download")
            ->assertStatus(410);
    }
}

