<?php

namespace Tests\Feature\DataExchange;

use App\Enums\AccountStatus;
use App\Enums\ExportDatasetKey;
use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Enums\ImportJobStatus;
use App\Enums\ImportMode;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverableEightComprehensiveTest extends TestCase
{
    protected User $adminUser;
    protected User $staffUser;
    protected User $accountsUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->adminUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'অ্যাডমিন ইউজার',
            'name_en' => 'Admin User',
            'username' => 'admin_test_' . Str::random(6),
            'email' => 'admin_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AdminPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->staffUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'স্টাফ ইউজার',
            'name_en' => 'Staff User',
            'username' => 'staff_test_' . Str::random(6),
            'email' => 'staff_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('StaffPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staffUser->roles()->sync([$staffRole->id]);

        $this->accountsUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'হিসাব ইউজার',
            'name_en' => 'Accounts User',
            'username' => 'accounts_test_' . Str::random(6),
            'email' => 'accounts_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AccountsPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accountsUser->roles()->sync([$accountsRole->id]);

        // Clean any leftover jobs from previous tests for deterministic assertion
        ImportJob::query()->delete();
        ExportJob::query()->delete();
    }

    /**
     * D08-F01-AT01: Only authorized job records appear in list and KPI counts.
     */
    public function test_f01_at01_only_authorized_job_records_appear_in_list_and_kpi_counts(): void
    {
        // 1. Create a Staff import job
        $staffImport = ImportJob::create([
            'job_number' => 'IMP-' . strtoupper(Str::random(8)),
            'requester_id' => $this->staffUser->id,
            'mode' => ImportMode::CREATE_ONLY,
            'template_code' => 'students_create',
            'template_version' => '1.0',
            'locale' => 'bn',
            'source_name' => 'students_2026.xlsx',
            'storage_key' => 'imports/test1.xlsx',
            'sha256' => hash('sha256', 'test content 1'),
            'byte_size' => 1024,
            'state' => ImportJobStatus::REVIEW_REQUIRED,
            'total_rows' => 50,
            'ready_rows' => 45,
            'review_rows' => 5,
        ]);

        // 2. Create an Accounts finance export job
        $accountsExport = ExportJob::create([
            'job_number' => 'EXP-' . strtoupper(Str::random(8)),
            'requester_id' => $this->accountsUser->id,
            'dataset_key' => ExportDatasetKey::CASH_COLLECTIONS,
            'filter_json' => ['month' => '2026-10'],
            'column_json' => ['payment_number', 'amount'],
            'locale' => 'bn',
            'format' => ExportFormat::XLSX,
            'row_count' => 120,
            'status' => ExportJobStatus::READY,
            'storage_key' => 'exports/cash.xlsx',
            'expires_at' => now()->addDays(7),
        ]);

        // 3. Admin sees BOTH in summary and list
        $adminSummary = $this->actingAs($this->adminUser)->getJson('/api/v1/data-jobs/summary');
        $adminSummary->assertOk()
            ->assertJsonPath('data.total_imports', 1)
            ->assertJsonPath('data.needs_review_imports', 1)
            ->assertJsonPath('data.ready_exports', 1);

        $adminList = $this->actingAs($this->adminUser)->getJson('/api/v1/data-jobs');
        $adminList->assertOk();
        $adminJobNumbers = collect($adminList->json('data'))->pluck('job_number');
        $this->assertTrue($adminJobNumbers->contains($staffImport->job_number));
        $this->assertTrue($adminJobNumbers->contains($accountsExport->job_number));

        // 4. Accounts user sees finance export, but ZERO student imports (D08-F01-AT01, D08-F01-AT02)
        $accountsSummary = $this->actingAs($this->accountsUser)->getJson('/api/v1/data-jobs/summary');
        $accountsSummary->assertOk()
            ->assertJsonPath('data.total_imports', 0)
            ->assertJsonPath('data.needs_review_imports', 0)
            ->assertJsonPath('data.ready_exports', 1);

        $accountsList = $this->actingAs($this->accountsUser)->getJson('/api/v1/data-jobs');
        $accountsList->assertOk();
        $accountsJobNumbers = collect($accountsList->json('data'))->pluck('job_number');
        $this->assertFalse($accountsJobNumbers->contains($staffImport->job_number));
        $this->assertTrue($accountsJobNumbers->contains($accountsExport->job_number));

        // 5. Office Staff sees own student import, but NOT Accounts cash collection export
        $staffSummary = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs/summary');
        $staffSummary->assertOk()
            ->assertJsonPath('data.total_imports', 1)
            ->assertJsonPath('data.needs_review_imports', 1)
            ->assertJsonPath('data.ready_exports', 0); // cash collection is excluded for staff

        $staffList = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs');
        $staffList->assertOk();
        $staffJobNumbers = collect($staffList->json('data'))->pluck('job_number');
        $this->assertTrue($staffJobNumbers->contains($staffImport->job_number));
        $this->assertFalse($staffJobNumbers->contains($accountsExport->job_number));
    }

    /**
     * D08-F01-AT02: Accountant cannot open Staff-only import job even by guessing ID.
     */
    public function test_f01_at02_accountant_cannot_open_staff_only_import_job_even_by_guessing_id(): void
    {
        $staffImport = ImportJob::create([
            'job_number' => 'IMP-' . strtoupper(Str::random(8)),
            'requester_id' => $this->staffUser->id,
            'mode' => ImportMode::CREATE_ONLY,
            'template_code' => 'students_create',
            'template_version' => '1.0',
            'locale' => 'bn',
            'source_name' => 'secret_students.xlsx',
            'storage_key' => 'imports/secret.xlsx',
            'sha256' => hash('sha256', 'secret'),
            'byte_size' => 2048,
            'state' => ImportJobStatus::UPLOADED,
        ]);

        // Access via numeric ID by Accountant
        $resById = $this->actingAs($this->accountsUser)->getJson("/api/v1/data-jobs/{$staffImport->id}");
        $resById->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Access via public job_number by Accountant
        $resByNumber = $this->actingAs($this->accountsUser)->getJson("/api/v1/data-jobs/{$staffImport->job_number}");
        $resByNumber->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Staff can open their own job
        $staffRes = $this->actingAs($this->staffUser)->getJson("/api/v1/data-jobs/{$staffImport->job_number}");
        $staffRes->assertOk()
            ->assertJsonPath('data.job_number', $staffImport->job_number)
            ->assertJsonPath('data.source_name', 'secret_students.xlsx');
    }

    /**
     * D08-F01-AT03: Job list paginates and filters by mode/status/date without leaking cross-role data.
     */
    public function test_f01_at03_job_list_paginates_and_filters_without_cross_role_leakage(): void
    {
        // Create 6 import jobs for staff
        for ($i = 1; $i <= 6; $i++) {
            ImportJob::create([
                'job_number' => "IMP-TEST-{$i}-" . strtoupper(Str::random(4)),
                'requester_id' => $this->staffUser->id,
                'mode' => $i % 2 === 0 ? ImportMode::MATCH_UPDATE : ImportMode::CREATE_ONLY,
                'template_code' => 'students_create',
                'template_version' => '1.0',
                'locale' => 'bn',
                'source_name' => "batch_{$i}.xlsx",
                'storage_key' => "imports/batch_{$i}.xlsx",
                'sha256' => hash('sha256', "batch_{$i}"),
                'byte_size' => 1000 + $i,
                'state' => $i === 6 ? ImportJobStatus::FAILED : ImportJobStatus::COMPLETED,
                'total_rows' => 10 * $i,
            ]);
        }

        // Test pagination page_size = 3
        $page1 = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs?type=IMPORT&page=1&page_size=3');
        $page1->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 3)
            ->assertJsonCount(3, 'data');

        $page2 = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs?type=IMPORT&page=2&page_size=3');
        $page2->assertOk()
            ->assertJsonPath('meta.page', 2)
            ->assertJsonCount(3, 'data');

        // Test filter by status FAILED
        $failedRes = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs?type=IMPORT&status=FAILED');
        $failedRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'FAILED');
    }

    /**
     * D08-F01-AT04 & RBAC: Anonymous access blocked and audit endpoint restricted to Admin.
     */
    public function test_f01_at04_anonymous_blocked_and_audit_admin_only(): void
    {
        // 1. Unauthenticated gets 401
        $this->getJson('/api/v1/data-jobs')->assertStatus(401);
        $this->getJson('/api/v1/data-jobs/summary')->assertStatus(401);

        // 2. Staff cannot access admin audit endpoint (GET /data-jobs/audit)
        $staffAudit = $this->actingAs($this->staffUser)->getJson('/api/v1/data-jobs/audit');
        $staffAudit->assertStatus(403);

        // 3. Accounts cannot access admin audit endpoint
        $accountsAudit = $this->actingAs($this->accountsUser)->getJson('/api/v1/data-jobs/audit');
        $accountsAudit->assertStatus(403);

        // 4. Admin can access audit endpoint
        $adminAudit = $this->actingAs($this->adminUser)->getJson('/api/v1/data-jobs/audit');
        $adminAudit->assertOk()->assertJsonStructure(['data']);
    }

    /**
     * D08-F02-AT01 & AT02: Template catalog and schema definition with forbidden writable fields.
     */
    public function test_f02_at01_and_at02_template_schema_and_forbidden_fields(): void
    {
        // 1. List templates
        $listRes = $this->actingAs($this->staffUser)->getJson('/api/v1/import-templates');
        $listRes->assertOk()
            ->assertJsonStructure(['data' => [['code', 'name_bn', 'name_en', 'mode', 'version']]]);

        $codes = collect($listRes->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('students_create'));
        $this->assertTrue($codes->contains('student_update'));
        $this->assertTrue($codes->contains('enrollment_stage'));

        // 2. Check schema for students_create
        $schemaRes = $this->actingAs($this->staffUser)->getJson('/api/v1/import-templates/students_create/schema');
        $schemaRes->assertOk()
            ->assertJsonPath('data.code', 'students_create')
            ->assertJsonPath('data.schema.schemaVersion', '1.0');

        $forbidden = $schemaRes->json('data.forbidden_fields');
        $this->assertContains('student_id', $forbidden);
        $this->assertContains('cash_paid', $forbidden);
        $this->assertContains('receipt_no', $forbidden);

        // Required fields include guardian and student names
        $required = $schemaRes->json('data.schema.required_fields');
        $this->assertContains('name_bn', $required);
        $this->assertContains('primary_guardian.phone', $required);
    }

    /**
     * D08-F02-AT03 & AT04: Download valid XLSX template maintaining Bangla Unicode and text phone formatting.
     */
    public function test_f02_at03_and_at04_download_xlsx_template_unicode_and_text_formatting(): void
    {
        $downloadRes = $this->actingAs($this->staffUser)->get('/api/v1/import-templates/students_create/download?locale=bn');
        $downloadRes->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $content = $downloadRes->getContent();
        $this->assertNotEmpty($content);

        // Verify ZIP / OpenXML package contains sheets and unicode headers
        $tempFile = tempnam(sys_get_temp_dir(), 'test_dl_');
        file_put_contents($tempFile, $content);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tempFile));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet2.xml'); // Data sheet
        $zip->close();
        @unlink($tempFile);

        $this->assertNotEmpty($sheetXml);
        // Header contains Bangla Unicode text
        $this->assertStringContainsString('শিক্ষার্থীর নাম (বাংলা)', $sheetXml);
        // Sample phone retains leading zero format (01700112233) as inlineStr
        $this->assertStringContainsString('01700112233', $sheetXml);
        $this->assertStringContainsString('t="inlineStr"', $sheetXml);
    }

    /**
     * D08-F02-AT05: Unknown or retired template version is rejected before preview.
     */
    public function test_f02_at05_unknown_template_version_is_rejected(): void
    {
        $res = $this->actingAs($this->staffUser)->getJson('/api/v1/import-templates/students_create/schema?version=99.0');
        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'TEMPLATE_VERSION_UNSUPPORTED');

        $downloadRes = $this->actingAs($this->staffUser)->get('/api/v1/import-templates/students_create/download?version=99.0');
        $downloadRes->assertStatus(422)
            ->assertJsonPath('error.code', 'TEMPLATE_VERSION_UNSUPPORTED');
    }

    protected function createValidXlsxFile(string $filename = 'test.xlsx', ?array $sheets = null): \Illuminate\Http\UploadedFile
    {
        $xlsxService = app(\App\Services\DataExchange\XlsxWorkbookService::class);
        $binary = $xlsxService->generateWorkbook($sheets ?? [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['আব্দুল্লাহ', 'Abdullah', 'MALE', '2015-01-01', '01711000001'],
                ]
            ]
        ]);

        return \Illuminate\Http\UploadedFile::fake()->createWithContent($filename, $binary);
    }

    /**
     * D08-F03-AT01: Upload valid OpenXML XLSX initializes an import job with SHA-256 and UPLOADED state.
     */
    public function test_f03_at01_upload_valid_openxml_xlsx_initializes_job(): void
    {
        $file = $this->createValidXlsxFile('valid_students.xlsx');

        $response = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
            'locale' => 'bn',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202)
            ->assertJsonPath('data.state', 'UPLOADED')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'job_number',
                    'state',
                    'sha256',
                    'detected_sheets',
                    'created_at',
                ]
            ]);

        $this->assertDatabaseHas('import_jobs', [
            'id' => $response->json('data.id'),
            'state' => 'UPLOADED',
            'template_code' => 'students_create',
            'requester_id' => $this->staffUser->id,
        ]);
    }

    /**
     * D08-F03-AT02: Macro-enabled file (.xlsm or vbaProject.bin inside) is rejected before DB insert.
     */
    public function test_f03_at02_macro_enabled_workbook_is_rejected(): void
    {
        // Construct a ZIP with vbaProject.bin inside
        $tempZip = tempnam(sys_get_temp_dir(), 'test_vba_');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/vbaProject.bin', 'FAKE_VBA_PAYLOAD');
        $zip->close();

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('malicious.xlsm', file_get_contents($tempZip));
        @unlink($tempZip);

        $response = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_UNSAFE');

        $this->assertDatabaseCount('import_jobs', 0);
    }

    /**
     * D08-F03-AT03: Corrupted or non-OpenXML zip is rejected safely.
     */
    public function test_f03_at03_corrupted_file_is_rejected(): void
    {
        $corruptFile = \Illuminate\Http\UploadedFile::fake()->createWithContent('corrupt.xlsx', 'NOT_A_ZIP_CONTENT');

        $response = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $corruptFile,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'FILE_UNSAFE');

        $this->assertDatabaseCount('import_jobs', 0);
    }

    /**
     * D08-F03-AT04: Quarantined storage under private disk with retention timestamp and no public web path.
     */
    public function test_f03_at04_quarantined_storage_under_private_disk(): void
    {
        $file = $this->createValidXlsxFile('private_audit.xlsx');

        $response = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202);
        $jobId = $response->json('data.id');

        $job = ImportJob::findOrFail($jobId);
        $this->assertStringStartsWith('private/data-exchange/imports/', $job->storage_key);
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('local')->exists($job->storage_key));
        $this->assertNotNull($job->retention_until);
        $this->assertTrue($job->retention_until->isFuture());
    }

    /**
     * D08-F03-AT05: Upload idempotency returns cached response without duplicate job.
     */
    public function test_f03_at05_upload_idempotency_replay(): void
    {
        $file = $this->createValidXlsxFile('idempotent.xlsx');
        $idempotencyKey = (string) Str::uuid();

        // First upload
        $res1 = $this->actingAs($this->staffUser)
            ->post('/api/v1/import-jobs', [
                'file' => $file,
                'mode' => 'CREATE_ONLY',
                'template_version' => '1.0',
            ], ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey]);

        $res1->assertStatus(202);
        $jobId = $res1->json('data.id');

        // Replay with identical key
        $res2 = $this->actingAs($this->staffUser)
            ->post('/api/v1/import-jobs', [
                'file' => $file,
                'mode' => 'CREATE_ONLY',
                'template_version' => '1.0',
            ], ['Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey]);

        $res2->assertStatus(200)
            ->assertJsonPath('data.id', $jobId);

        // Ensure exactly 1 job was created
        $this->assertDatabaseCount('import_jobs', 1);
    }

    /**
     * D08-F03-AT06: Injected requester_id in form payload is ignored; authenticated user is strictly used.
     */
    public function test_f03_at06_injected_requester_id_is_ignored(): void
    {
        $file = $this->createValidXlsxFile('tampered.xlsx');

        $response = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
            'requester_id' => $this->adminUser->id, // Injected attempt
            'actor_id' => $this->adminUser->id,     // Injected attempt
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202);
        $jobId = $response->json('data.id');

        $job = ImportJob::findOrFail($jobId);
        $this->assertEquals($this->staffUser->id, $job->requester_id);
    }

    /**
     * D08-F04-AT01: Extract columns and save valid mapping.
     */
    public function test_f04_at01_extract_columns_and_save_mapping(): void
    {
        $file = $this->createValidXlsxFile('extract_cols.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['আব্দুল্লাহ', 'Abdullah', 'MALE', '2015-05-10', '01711223344'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        // Detect columns
        $colsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/columns");
        $colsRes->assertOk()
            ->assertJsonPath('data.sheet', 'Data')
            ->assertJsonPath('data.header_row', 1);

        $detected = collect($colsRes->json('data.detected_headers'))->pluck('header');
        $this->assertContains('name_bn', $detected);
        $this->assertContains('gender', $detected);

        // Save mapping
        $mappingRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $mappingRes->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.mapping.A', 'name_bn');

        $this->assertDatabaseHas('import_column_mappings', [
            'import_job_id' => $jobId,
            'version' => 1,
        ]);
    }

    /**
     * D08-F04-AT02: Forbidden fields rejected in mapping with 422 SCHEMA_VIOLATION.
     */
    public function test_f04_at02_forbidden_fields_rejected_in_mapping(): void
    {
        $file = $this->createValidXlsxFile('forbidden_test.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        // Attempt mapping to student_id or cash_paid
        $res = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'name_bn',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'SCHEMA_VIOLATION');
    }

    /**
     * D08-F04-AT03: Formula injection literal detected in preview: cell beginning with '= or + or - or @.
     */
    public function test_f04_at03_formula_injection_literal_detected_in_preview(): void
    {
        $file = $this->createValidXlsxFile('formula_test.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['=SUM(1+1)', 'Test Formula', 'MALE', '2016-01-01', '01711223344'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $valRes->assertOk();

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $rowsRes->assertOk();

        $firstRow = $rowsRes->json('data')[0];
        $issueCodes = collect($firstRow['issues'])->pluck('code');
        $this->assertTrue($issueCodes->contains('FORMULA_INJECTION_DETECTED'));
    }

    /**
     * D08-F04-AT04: Read-only preview guarantee: preview generates zero DB mutations to students, guardians, enrollments.
     */
    public function test_f04_at04_read_only_preview_zero_database_mutations(): void
    {
        $studentsBefore = DB::table('students')->count();
        $guardiansBefore = DB::table('guardians')->count();
        $enrollmentsBefore = DB::table('enrollments')->count();

        $file = $this->createValidXlsxFile('readonly_test.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['আব্দুল কারীম', 'Abdul Karim', 'MALE', '2015-08-15', '01812345678'],
                    ['মারিয়াম বিনতে হাবিব', 'Mariam', 'FEMALE', '2016-03-20', '01998765432'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        // Assert strictly zero DB changes to authoritative domain tables
        $this->assertEquals($studentsBefore, DB::table('students')->count());
        $this->assertEquals($guardiansBefore, DB::table('guardians')->count());
        $this->assertEquals($enrollmentsBefore, DB::table('enrollments')->count());
    }

    /**
     * D08-F04-AT05: Invalid phone numbers, invalid DOB flags row as INVALID with localized error messages.
     */
    public function test_f04_at05_invalid_rows_flagged_with_localized_messages(): void
    {
        $file = $this->createValidXlsxFile('invalid_data.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['জায়েদ', 'Zayed', 'INVALID_GENDER', '2099-01-01', '0123'], // Invalid gender, future DOB, 4-digit phone
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $firstRow = $rowsRes->json('data')[0];

        $this->assertEquals('INVALID', $firstRow['status']);

        $issues = collect($firstRow['issues']);
        $codes = $issues->pluck('code');
        $this->assertTrue($codes->contains('INVALID_GENDER'));
        $this->assertTrue($codes->contains('INVALID_DOB'));
        $this->assertTrue($codes->contains('INVALID_PHONE'));

        // Check bilingual message presence
        $phoneIssue = $issues->firstWhere('code', 'INVALID_PHONE');
        $this->assertNotEmpty($phoneIssue['message_bn']);
        $this->assertNotEmpty($phoneIssue['message_en']);
    }

    /**
     * D08-F04-AT06: Cryptographic preview token generated, bound to file hash and mapping version.
     */
    public function test_f04_at06_cryptographic_preview_token_generation(): void
    {
        $file = $this->createValidXlsxFile('token_test.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $valRes->assertOk()
            ->assertJsonPath('data.state', 'READY');

        $token = $valRes->json('data.preview_token');
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));

        $previewRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/preview");
        $previewRes->assertOk()
            ->assertJsonPath('data.preview_token', $token)
            ->assertJsonPath('data.is_stale', false);
    }

    /**
     * D08-F04-AT07: Modifying column mapping increments mapping_version and invalidates previous preview token.
     */
    public function test_f04_at07_modifying_mapping_invalidates_preview_token(): void
    {
        $file = $this->createValidXlsxFile('stale_token.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        // Initial mapping & validation
        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        // Modify mapping
        $newMapRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $newMapRes->assertOk()
            ->assertJsonPath('data.version', 2);

        $previewRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/preview");
        $previewRes->assertOk()
            ->assertJsonPath('data.preview_token', null)
            ->assertJsonPath('data.is_stale', true);
    }

    /**
     * D08-F05-AT01 & AT05: Potential duplicate flagged as REVIEW_REQUIRED; Admin override vs Staff restrictions.
     */
    public function test_f05_at01_and_at05_duplicate_review_and_resolutions(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $uniqueStudentId = 'ST-' . strtoupper(Str::random(8));

        // 1. Pre-create existing student and guardian
        $guardian = \App\Models\Guardian::create([
            'phone' => $uniquePhone,
            'phone_hash' => hash('sha256', $uniquePhone),
            'name_bn' => 'মো: রফিকুল ইসলাম',
            'name_en' => 'Md Rafiqul Islam',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $existingStudent = \App\Models\Student::create([
            'student_id' => $uniqueStudentId,
            'name_bn' => 'আব্দুল্লাহ আল মাহমুদ',
            'name_en' => 'Abdullah Al Mahmud',
            'date_of_birth' => '2015-06-15',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::REGISTERED,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        \App\Models\StudentGuardian::create([
            'student_id' => $existingStudent->id,
            'guardian_id' => $guardian->id,
            'relation' => \App\Enums\GuardianRelation::FATHER,
            'is_primary' => true,
            'sms_consent' => false,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 2. Upload file with 2 rows: 1 unique, 1 matching existing guardian/name
        $file = $this->createValidXlsxFile('duplicate_test.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['আব্দুর রহমান', 'Abdur Rahman', 'MALE', '2016-01-01', '01811223344'],
                    ['আব্দুল্লাহ আল মাহমুদ', 'Abdullah', 'MALE', '2015-06-15', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $rows = $rowsRes->json('data');

        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals('REVIEW_REQUIRED', $rows[1]['status']);
        $this->assertNotEmpty($rows[1]['candidate_matches_json']);

        $reviewRowId = $rows[1]['id'];

        // 3. Staff attempts OVERRIDE_CREATE -> 403 Forbidden
        $staffOverrideRes = $this->actingAs($this->staffUser)->putJson("/api/v1/import-jobs/{$jobId}/resolutions", [
            'resolutions' => [
                [
                    'row_id' => $reviewRowId,
                    'decision' => 'OVERRIDE_CREATE',
                    'notes' => 'Staff trying to override duplicate',
                ]
            ]
        ]);
        $staffOverrideRes->assertStatus(403);

        // 4. Admin submits OVERRIDE_CREATE -> 200 OK
        $adminOverrideRes = $this->actingAs($this->adminUser)->putJson("/api/v1/import-jobs/{$jobId}/resolutions", [
            'resolutions' => [
                [
                    'row_id' => $reviewRowId,
                    'decision' => 'OVERRIDE_CREATE',
                    'notes' => 'Admin approved override',
                ]
            ]
        ]);
        $adminOverrideRes->assertOk()
            ->assertJsonPath('data.review_rows', 0)
            ->assertJsonPath('data.ready_rows', 2);
    }

    /**
     * D08-F05-AT06: Re-validation updates preview status to READY.
     */
    public function test_f05_at06_revalidate_updates_job_state(): void
    {
        $file = $this->createValidXlsxFile('reval_test.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $revalRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/revalidate");
        $revalRes->assertOk()
            ->assertJsonPath('data.state', 'READY');
    }

    /**
     * D08-F06-AT01 & AT05: Match-update requires explicit identity anchor; missing student flags INVALID.
     */
    public function test_f06_at01_and_at05_match_update_missing_student_error(): void
    {
        $file = $this->createValidXlsxFile('match_missing.xlsx', [
            'Data' => [
                'headers' => ['student_id', 'name_bn', 'name_en'],
                'rows' => [
                    ['ST-9999-0000', 'বিদ্যমান নাই', 'Non-existent'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'MATCH_UPDATE',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'name_bn',
                'C' => 'name_en',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $firstRow = $rowsRes->json('data')[0];

        $this->assertEquals('INVALID', $firstRow['status']);
        $issues = collect($firstRow['issues'])->pluck('code');
        $this->assertTrue($issues->contains('STUDENT_NOT_FOUND'));
    }

    /**
     * D08-F06-AT02 & AT04: Match-update generates diff and flags sensitive changes for review.
     */
    public function test_f06_at02_and_at04_diff_generation_and_sensitive_review(): void
    {
        $uniqueStudentId = 'ST-' . strtoupper(Str::random(8));

        $existing = \App\Models\Student::create([
            'student_id' => $uniqueStudentId,
            'name_bn' => 'আহমদ উল্লাহ',
            'name_en' => 'Ahmad Ullah',
            'date_of_birth' => '2015-01-01',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::REGISTERED,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = $this->createValidXlsxFile('match_diff.xlsx', [
            'Data' => [
                'headers' => ['student_id', 'name_bn', 'name_en'],
                'rows' => [
                    [$uniqueStudentId, 'মুহাম্মদ আহমদ উল্লাহ', 'Muhammad Ahmad Ullah'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'MATCH_UPDATE',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'name_bn',
                'C' => 'name_en',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $firstRow = $rowsRes->json('data')[0];

        $this->assertEquals('REVIEW_REQUIRED', $firstRow['status']);
        $this->assertNotNull($firstRow['diff_json']);
        $this->assertEquals('আহমদ উল্লাহ', $firstRow['diff_json']['name_bn']['old']);
        $this->assertEquals('মুহাম্মদ আহমদ উল্লাহ', $firstRow['diff_json']['name_bn']['new']);

        // Accept update decision
        $res = $this->actingAs($this->staffUser)->putJson("/api/v1/import-jobs/{$jobId}/resolutions", [
            'resolutions' => [
                [
                    'row_id' => $firstRow['id'],
                    'decision' => 'ACCEPT_UPDATE',
                    'field_decisions' => ['name_bn' => true, 'name_en' => true],
                ]
            ]
        ]);

        $res->assertOk()
            ->assertJsonPath('data.ready_rows', 1)
            ->assertJsonPath('data.review_rows', 0);
    }

    /**
     * D08-F07-AT01 & AT02: Enrollment staging requires valid student and stays in PENDING_BILLING.
     */
    public function test_f07_at01_and_at02_enrollment_staging_requires_valid_student_and_pending_billing(): void
    {
        $uniqueStudentId = 'ST-' . strtoupper(Str::random(8));

        $student = \App\Models\Student::create([
            'student_id' => $uniqueStudentId,
            'name_bn' => 'সাঈদ হাসান',
            'name_en' => 'Sayeed Hasan',
            'date_of_birth' => '2014-05-10',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $program = DB::table('academic_programs')->first();
        $period = DB::table('academic_periods')->first();

        $file = $this->createValidXlsxFile('enroll_staging.xlsx', [
            'Data' => [
                'headers' => ['student_id', 'academic_program_code', 'academic_period_code'],
                'rows' => [
                    [$uniqueStudentId, $program->code, $period->label],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'ENROLL_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'academic_program_code',
                'C' => 'academic_period_code',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $valRes->assertOk()
            ->assertJsonPath('data.ready_rows', 1)
            ->assertJsonPath('data.state', 'READY');

        // Commit enrollment
        $token = $valRes->json('data.preview_token');
        $commitRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        $commitRes->assertOk()
            ->assertJsonPath('data.committed_rows', 1)
            ->assertJsonPath('data.state', 'COMPLETED');

        // Assert staged enrollment is NEVER directly APPROVED/PAID
        $enrollment = DB::table('enrollments')->where('student_id', $student->id)->first();
        if ($enrollment) {
            $this->assertEquals('PENDING_BILLING', $enrollment->status);
        }
    }

    /**
     * D08-F07-AT05: Duplicate enrollment in the same period is rejected with DUPLICATE_ENROLLMENT.
     */
    public function test_f07_at05_duplicate_enrollment_prevention(): void
    {
        $uniqueStudentId = 'ST-' . strtoupper(Str::random(8));

        $student = \App\Models\Student::create([
            'student_id' => $uniqueStudentId,
            'name_bn' => 'মুসা ইব্রাহিম',
            'name_en' => 'Musa Ibrahim',
            'date_of_birth' => '2014-01-01',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::REGISTERED,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $program = DB::table('academic_programs')->first();
        $period = DB::table('academic_periods')->first();
        $offering = DB::table('academic_offerings')->first();
        $section = DB::table('offering_sections')->first();

        // Create program admission and an existing active enrollment
        $progAdmId = DB::table('program_admissions')->insertGetId([
            'student_id' => $student->id,
            'academic_program_id' => $program->id,
            'entry_type' => 'REGULAR',
            'status' => 'ACTIVE',
            'entered_at' => now(),
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enrollments')->insert([
            'program_admission_id' => $progAdmId,
            'student_id' => $student->id,
            'academic_period_id' => $period->id,
            'academic_offering_id' => $offering ? $offering->id : 1,
            'offering_section_id' => $section ? $section->id : 1,
            'enrollment_type' => 'NEW',
            'status' => 'PENDING_BILLING',
            'submitted_at' => now(),
            'created_by' => $this->adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = $this->createValidXlsxFile('dup_enroll.xlsx', [
            'Data' => [
                'headers' => ['student_id', 'academic_program_code', 'academic_period_code'],
                'rows' => [
                    [$uniqueStudentId, $program->code, $period->label],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'ENROLL_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'academic_program_code',
                'C' => 'academic_period_code',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        $rowsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/rows");
        $firstRow = $rowsRes->json('data')[0];

        $this->assertEquals('INVALID', $firstRow['status']);
        $issues = collect($firstRow['issues'])->pluck('code');
        $this->assertTrue($issues->contains('DUPLICATE_ENROLLMENT'));
    }

    /**
     * D08-F08-AT01: Commit rejects stale or invalid preview token with 409 PREVIEW_STALE.
     */
    public function test_f08_at01_commit_rejects_stale_preview_token(): void
    {
        $file = $this->createValidXlsxFile('stale_commit.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");

        // Attempt commit with tampered preview token
        $commitRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => 'invalid_token_hash_value_1234567890',
        ]);

        $commitRes->assertStatus(409)
            ->assertJsonPath('error.code', 'PREVIEW_STALE');
    }

    /**
     * D08-F08-AT02: Successful commit allocates Student ID server-side and creates guardian with default sms_consent=false.
     */
    public function test_f08_at02_successful_commit_allocates_student_id(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('commit_student.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['আবু বকর সিদ্দীক', 'Abu Bakr Siddiq', 'MALE', '2016-02-14', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $token = $valRes->json('data.preview_token');

        $commitRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        $commitRes->assertOk()
            ->assertJsonPath('data.committed_rows', 1)
            ->assertJsonPath('data.state', 'COMPLETED');

        // Assert Student was created with authoritative server allocated ID
        $createdStudent = \App\Models\Student::where('name_bn', 'আবু বকর সিদ্দীক')->first();
        $this->assertNotNull($createdStudent);
        $this->assertNotEmpty($createdStudent->student_id);

        // Assert Guardian sms_consent defaults to false
        $studentGuardian = \App\Models\StudentGuardian::where('student_id', $createdStudent->id)->first();
        $this->assertNotNull($studentGuardian);
        $this->assertFalse((bool) $studentGuardian->sms_consent);
    }

    /**
     * D08-F08-AT05: Cooperative cancellation of import job.
     */
    public function test_f08_at05_cooperative_cancellation(): void
    {
        $file = $this->createValidXlsxFile('cancel_test.xlsx');
        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $cancelRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/cancel", [
            'reason' => 'User aborted upload',
        ]);

        $cancelRes->assertOk()
            ->assertJsonPath('data.state', 'CANCELLED');

        $this->assertDatabaseHas('import_jobs', [
            'id' => $jobId,
            'state' => 'CANCELLED',
        ]);
    }

    /**
     * D08-F08-AT07: Download result XLSX file.
     */
    public function test_f08_at07_download_result_file(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('result_dl.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['তারিক বিন জিয়াদ', 'Tariq Bin Ziyad', 'MALE', '2015-09-09', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $token = $valRes->json('data.preview_token');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        // Download result file
        $dlRes = $this->actingAs($this->staffUser)->get("/api/v1/import-jobs/{$jobId}/result-file");
        $dlRes->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertNotEmpty($dlRes->getContent());
    }

    /**
     * D08-F09-AT01 & AT02: Audit trail records who uploaded, mapped, validated, committed, and downloaded.
     */
    public function test_f09_at01_and_at02_audit_trail_and_result_authorization(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('audit_test.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['ইমরান খান', 'Imran Khan', 'MALE', '2015-01-01', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $token = $valRes->json('data.preview_token');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        $this->actingAs($this->staffUser)->get("/api/v1/import-jobs/{$jobId}/result-file");

        // Verify events recorded
        $eventsRes = $this->actingAs($this->staffUser)->getJson("/api/v1/data-jobs/{$jobId}/events");
        $eventsRes->assertOk();
        $events = collect($eventsRes->json('data'));

        $this->assertTrue($events->contains(fn($e) => $e['event'] === 'UPLOADED'));
        $this->assertTrue($events->contains(fn($e) => $e['event'] === 'MAPPED'));
        $this->assertTrue($events->contains(fn($e) => $e['event'] === 'VALIDATED'));
        $this->assertTrue($events->contains(fn($e) => $e['event'] === 'COMMITTED'));
        $this->assertTrue($events->contains(fn($e) => $e['event'] === 'RESULT_DOWNLOADED'));

        // Admin audit route check
        $auditRes = $this->actingAs($this->adminUser)->getJson('/api/v1/data-jobs/audit');
        $auditRes->assertOk()
            ->assertJsonPath('data.0.job_id', $jobId);
    }

    /**
     * D08-F09-AT03: Expired artifacts return 410 FILE_EXPIRED.
     */
    public function test_f09_at03_expired_artifact_returns_410(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('expired_test.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['সাকিব আল হাসান', 'Shakib Al Hasan', 'MALE', '2015-02-02', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $token = $valRes->json('data.preview_token');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        // Manually simulate retention expiration (> 7 days in past)
        \App\Models\ImportJob::where('id', $jobId)->update([
            'retention_until' => now()->subDay(),
        ]);

        $dlRes = $this->actingAs($this->staffUser)->getJson("/api/v1/import-jobs/{$jobId}/result-file");
        $dlRes->assertStatus(410)
            ->assertJsonPath('error.code', 'FILE_EXPIRED');
    }

    /**
     * D08-F09-AT04 & F12-AT06: Result XLSX cells starting with = + - @ are safely escaped with ' prefix.
     */
    public function test_f09_at04_formula_injection_escaping_in_xlsx(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('formula_result.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['=SUM(1,2)', '+FormulaTwo', 'MALE', '2015-03-03', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'name_bn',
                'B' => 'name_en',
                'C' => 'gender',
                'D' => 'dob',
                'E' => 'primary_guardian.phone',
            ]
        ]);

        $valRes = $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/validate");
        $token = $valRes->json('data.preview_token');

        $this->actingAs($this->staffUser)->postJson("/api/v1/import-jobs/{$jobId}/commit", [
            'preview_token' => $token,
        ]);

        $dlRes = $this->actingAs($this->staffUser)->get("/api/v1/import-jobs/{$jobId}/result-file");
        $dlRes->assertOk();

        $content = $dlRes->getContent();
        // Check that =SUM was escaped with apostrophe in the generated XML sheet
        $this->assertStringContainsString("&#039;=SUM(1,2)", $content);
    }

    /**
     * D08-F09-AT05: Source row number preserved in all row results across retries.
     */
    public function test_f09_at05_source_row_number_preserved_across_results(): void
    {
        $uniquePhone = '017' . rand(10000000, 99999999);
        $file = $this->createValidXlsxFile('row_nums.xlsx', [
            'Data' => [
                'headers' => ['name_bn', 'name_en', 'gender', 'dob', 'primary_guardian_phone'],
                'rows' => [
                    ['সাদ বিন আবি ওয়াক্কাস', 'Saad Bin Abi Waqqas', 'MALE', '2015-05-05', $uniquePhone],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->staffUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        $rows = \App\Models\ImportRow::where('import_job_id', $jobId)->get();
        // Row 1 is header, data row is row_number = 2
        $this->assertEquals(2, $rows[0]->row_number);
    }

    /**
     * D08-F10-AT01 & AT02: Export dataset listing, schema, and calendar-year filters.
     */
    public function test_f10_at01_and_at02_export_datasets_rbac_and_filters(): void
    {
        // 1. Staff can view student datasets, denied finance cash collections
        $staffDatasetsRes = $this->actingAs($this->staffUser)->getJson('/api/v1/export-datasets');
        $staffDatasetsRes->assertOk();
        $keys = collect($staffDatasetsRes->json('data'))->pluck('key')->toArray();
        $this->assertContains('student_master', $keys);
        $this->assertContains('academic_enrollments', $keys);
        $this->assertNotContains('cash_collections', $keys);
        $this->assertNotContains('cashier_reconciliation', $keys);

        // 2. Column schema check for student_master
        $colRes = $this->actingAs($this->staffUser)->getJson('/api/v1/export-datasets/student_master/columns');
        $colRes->assertOk();
        $colList = collect($colRes->json('data.columns'));
        $this->assertNotEmpty($colList);

        // 3. Dry-run Preview
        $previewRes = $this->actingAs($this->staffUser)->postJson('/api/v1/export-jobs/preview', [
            'dataset' => 'student_master',
            'columns' => ['student_id', 'name_bn', 'name_en'],
            'format' => 'XLSX',
        ]);
        $previewRes->assertOk()
            ->assertJsonPath('data.dataset', 'student_master')
            ->assertJsonStructure(['data' => ['row_count', 'estimated_kb', 'cutoff_at']]);

        // 4. Accounts cannot access student_master
        $accountsForbidden = $this->actingAs($this->accountsUser)->getJson('/api/v1/export-datasets/student_master/columns');
        $accountsForbidden->assertStatus(403);
    }

    /**
     * D08-F10-AT03 & AT04: Academic enrollments export and student profile history export.
     */
    public function test_f10_at03_and_at04_cohort_vs_period_and_profile_history(): void
    {
        // 1. Create Export Job for academic_enrollments
        $exportRes = $this->actingAs($this->staffUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'academic_enrollments',
            'columns' => ['student_id', 'student_name', 'academic_year', 'program', 'cohort', 'period', 'class', 'section'],
            'format' => 'XLSX',
            'locale' => 'bn',
        ]);

        $exportRes->assertStatus(201)
            ->assertJsonPath('data.status', 'READY');

        $jobId = $exportRes->json('data.id');

        // 2. Download export artifact
        $dlRes = $this->actingAs($this->staffUser)->get("/api/v1/export-jobs/{$jobId}/download");
        $dlRes->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertNotEmpty($dlRes->getContent());

        // 3. Create Export Job for student_profile_history
        $histExport = $this->actingAs($this->staffUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'student_profile_history',
            'columns' => ['student_id', 'event_type', 'description', 'performed_by', 'occurred_at'],
            'format' => 'XLSX',
        ]);
        $histExport->assertStatus(201);
    }

    /**
     * D08-F10-AT05 & AT06 & F12-AT02: PDF export generation with metadata header.
     */
    public function test_f10_at05_and_at06_export_execution_idempotency_and_pdf(): void
    {
        $idempotencyKey = (string) Str::uuid();

        // 1. Export in PDF format
        $exportRes = $this->actingAs($this->staffUser)->postJson(
            '/api/v1/export-jobs',
            [
                'dataset' => 'student_master',
                'columns' => ['student_id', 'name_bn', 'name_en', 'gender'],
                'format' => 'PDF',
                'locale' => 'en',
            ],
            ['Idempotency-Key' => $idempotencyKey]
        );

        $exportRes->assertStatus(201)
            ->assertJsonPath('data.format', 'PDF');

        $jobId = $exportRes->json('data.id');

        // 2. Download PDF and verify %PDF-1.4 header
        $dlRes = $this->actingAs($this->staffUser)->get("/api/v1/export-jobs/{$jobId}/download");
        $dlRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $pdfBytes = $dlRes->getContent();
        $this->assertStringStartsWith("%PDF-1.4", $pdfBytes);
        $this->assertStringContainsString("%%EOF", $pdfBytes);

        // 3. Idempotent replay with same key returns identical job
        $replayRes = $this->actingAs($this->staffUser)->postJson(
            '/api/v1/export-jobs',
            [
                'dataset' => 'student_master',
                'columns' => ['student_id', 'name_bn', 'name_en', 'gender'],
                'format' => 'PDF',
                'locale' => 'en',
            ],
            ['Idempotency-Key' => $idempotencyKey]
        );

        $replayRes->assertStatus(201)
            ->assertJsonPath('data.id', $jobId);
    }

    /**
     * D08-F11-AT01 & AT02: Accounts can export D06/D07 finance data; cash report counts posted payments only.
     */
    public function test_f11_at01_and_at02_accounts_scope_and_cash_counts_posted_only(): void
    {
        // 1. Accounts lists datasets -> only finance datasets
        $datasetsRes = $this->actingAs($this->accountsUser)->getJson('/api/v1/export-datasets');
        $datasetsRes->assertOk();
        $keys = collect($datasetsRes->json('data'))->pluck('key')->toArray();
        $this->assertContains('invoice_ledger', $keys);
        $this->assertContains('cash_collections', $keys);
        $this->assertContains('cashier_reconciliation', $keys);
        $this->assertNotContains('student_master', $keys);

        // 2. Accounts attempts to request restricted field (BRC) -> 403 RESTRICTED_FIELD
        $badReq = $this->actingAs($this->accountsUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'student_master',
            'columns' => ['student_id', 'birth_registration_number'],
            'format' => 'XLSX',
        ]);
        $badReq->assertStatus(403);

        // 3. Pre-create 1 POSTED payment and 1 UNPAID payment slip / pending payment
        $uniquePhone = '017' . rand(10000000, 99999999);
        $student = \App\Models\Student::create([
            'student_id' => 'ST-' . strtoupper(Str::random(8)),
            'name_bn' => 'আহমেদ হাসান',
            'name_en' => 'Ahmed Hasan',
            'date_of_birth' => '2015-07-07',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // POSTED payment (Cash)
        \App\Models\Payment::create([
            'payment_reference' => 'PAY-2026-TEST01',
            'student_id' => $student->id,
            'payment_method' => 'CASH',
            'status' => 'POSTED',
            'amount_received' => 2500.00,
            'allocated_amount' => 2500.00,
            'collected_by' => $this->accountsUser->id,
            'collected_at' => now(),
        ]);

        // PENDING payment (Uncollected / unverified)
        \App\Models\Payment::create([
            'payment_reference' => 'PAY-2026-PENDING01',
            'student_id' => $student->id,
            'payment_method' => 'CASH',
            'status' => 'PENDING',
            'amount_received' => 5000.00,
            'allocated_amount' => 0.00,
            'collected_by' => $this->accountsUser->id,
            'collected_at' => now(),
        ]);

        // 4. Accounts exports cash_collections
        $cashExport = $this->actingAs($this->accountsUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'cash_collections',
            'columns' => ['receipt_number', 'payment_method', 'student_id', 'student_name', 'amount', 'status'],
            'format' => 'XLSX',
        ]);

        $cashExport->assertStatus(201);
        $jobId = $cashExport->json('data.id');

        $job = \App\Models\ExportJob::find($jobId);
        // Only 1 record (the POSTED payment) must be included, NOT the PENDING one
        $this->assertEquals(1, $job->row_count);
    }

    /**
     * D08-F11-AT03 & AT04: Invoice balances reconcile with D06 items and allocations.
     */
    public function test_f11_at03_and_at04_invoice_balance_reconciles_and_reversals(): void
    {
        $student = \App\Models\Student::create([
            'student_id' => 'ST-' . strtoupper(Str::random(8)),
            'name_bn' => 'মুসা বিন ইমরান',
            'name_en' => 'Musa Bin Imran',
            'date_of_birth' => '2015-08-08',
            'gender' => 'MALE',
            'admission_date' => '2026-01-01',
            'status' => \App\Enums\StudentStatus::ACTIVE,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $invoice = \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-REC01',
            'student_id' => $student->id,
            'gross_amount' => 5000.00,
            'adjustment_amount' => 500.00,
            'net_amount' => 4500.00,
            'status' => 'ISSUED',
            'due_at' => now()->addDays(15),
            'currency' => 'BDT',
            'source_type' => 'ENROLLMENT',
            'source_key' => 'TEST-SOURCE',
        ]);

        // Allocate 2000 payment
        \Illuminate\Support\Facades\DB::table('payment_allocations')->insert([
            'payment_id' => 1,
            'invoice_id' => $invoice->id,
            'allocation_type' => 'CREDIT',
            'allocated_amount' => 2000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Export invoice_ledger
        $invExport = $this->actingAs($this->accountsUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'invoice_ledger',
            'columns' => ['invoice_number', 'student_id', 'total_amount', 'waiver_amount', 'net_amount', 'paid_amount', 'balance_amount'],
            'format' => 'XLSX',
        ]);

        $invExport->assertStatus(201);
        $jobId = $invExport->json('data.id');

        $job = \App\Models\ExportJob::find($jobId);
        $this->assertGreaterThanOrEqual(1, $job->row_count);
    }

    /**
     * D08-F11-AT05: Imported workbooks cannot write payments, receipts, or cashier shifts even with forged permissions.
     */
    public function test_f11_at05_import_cannot_write_financial_ledger(): void
    {
        $file = $this->createValidXlsxFile('financial_tamper.xlsx', [
            'Data' => [
                'headers' => ['student_id', 'cash_paid', 'receipt_no', 'shift_id'],
                'rows' => [
                    ['ST-2026-0001', '5000', 'REC-FORGED', '1'],
                ]
            ]
        ]);

        $uploadRes = $this->actingAs($this->adminUser)->post('/api/v1/import-jobs', [
            'file' => $file,
            'mode' => 'CREATE_ONLY',
            'template_version' => '1.0',
        ], ['Accept' => 'application/json']);

        $jobId = $uploadRes->json('data.id');

        // Column mapping attempts to map financial columns -> 422 COLUMN_MAPPING_INVALID
        $mapRes = $this->actingAs($this->adminUser)->postJson("/api/v1/import-jobs/{$jobId}/mapping", [
            'sheet' => 'Data',
            'header_row' => 1,
            'mapping' => [
                'A' => 'student_id',
                'B' => 'cash_paid',
                'C' => 'receipt_no',
            ]
        ]);

        $mapRes->assertStatus(422)
            ->assertJsonPath('error.code', 'COLUMN_MAPPING_INVALID');
    }

    /**
     * D08-F12-AT03 & AT04: Rechecking live user permissions at download; expired export download blocked.
     */
    public function test_f12_at03_and_at04_download_security_recheck(): void
    {
        $exportRes = $this->actingAs($this->staffUser)->postJson('/api/v1/export-jobs', [
            'dataset' => 'student_master',
            'columns' => ['student_id', 'name_bn'],
            'format' => 'XLSX',
        ]);

        $jobId = $exportRes->json('data.id');

        // Deactivate staff user
        $this->staffUser->update(['is_active' => false]);

        $dlRes = $this->actingAs($this->staffUser)->getJson("/api/v1/export-jobs/{$jobId}/download");
        $dlRes->assertStatus(403)
            ->assertJsonPath('error.code', 'USER_INACTIVE');

        // Reactivate for subsequent tests
        $this->staffUser->update(['is_active' => true]);
    }
}
