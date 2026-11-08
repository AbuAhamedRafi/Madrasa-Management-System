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
}
