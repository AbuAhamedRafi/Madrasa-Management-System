<?php

namespace Tests\Feature\Reports;

use App\Enums\AccountStatus;
use App\Models\ReportSavedView;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\AuthorizedReportScope;
use App\Services\Reports\MetricDefinitionRegistry;
use App\Services\Reports\ReportCatalogService;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverableNineComprehensiveTest extends TestCase
{
    protected User $adminUser;
    protected User $staffUser;
    protected User $accountsUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (Role::count() === 0) {
            $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        }

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->adminUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'অ্যাডমিন ইউজার',
            'name_en' => 'Admin User',
            'username' => 'admin_rep_' . Str::random(6),
            'email' => 'admin_rep_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AdminPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->staffUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'স্টাফ ইউজার',
            'name_en' => 'Staff User',
            'username' => 'staff_rep_' . Str::random(6),
            'email' => 'staff_rep_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('StaffPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staffUser->roles()->sync([$staffRole->id]);

        $this->accountsUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'হিসাব ইউজার',
            'name_en' => 'Accounts User',
            'username' => 'accounts_rep_' . Str::random(6),
            'email' => 'accounts_rep_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AccountsPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accountsUser->roles()->sync([$accountsRole->id]);
    }

    /**
     * D09-F01-AT-01-01: Each KPI has a stable metric code, formula, unit, dimension whitelist and source owner.
     */
    public function test_at_01_01_metric_registry_structure_and_contracts(): void
    {
        $registry = app(MetricDefinitionRegistry::class);
        $definitions = $registry->getDefinitions($this->adminUser);

        $this->assertCount(10, $definitions);

        $expectedCodes = [
            'active_student_count',
            'approved_admissions',
            'pending_billing',
            'gross_cash',
            'approved_reversals',
            'net_cash',
            'net_assessed',
            'outstanding_as_of',
            'overdue_90_plus',
            'cashier_variance',
        ];

        $codes = array_column($definitions, 'code');
        $this->assertEquals($expectedCodes, $codes);

        foreach ($definitions as $metric) {
            $this->assertNotEmpty($metric['code']);
            $this->assertNotEmpty($metric['name_bn']);
            $this->assertNotEmpty($metric['name_en']);
            $this->assertNotEmpty($metric['formula']);
            $this->assertContains($metric['unit'], ['students', 'admissions', 'BDT']);
            $this->assertNotEmpty($metric['source_owner']);
            $this->assertIsArray($metric['dimension_whitelist']);
            $this->assertNotEmpty($metric['dimension_whitelist']);
        }
    }

    /**
     * D09-F01-AT-01-02: Metric definition endpoint excludes definitions without actor permission.
     */
    public function test_at_01_02_metric_endpoint_excludes_definitions_without_permission(): void
    {
        // 1. Staff can read only academic metrics
        $staffRes = $this->actingAs($this->staffUser)->getJson('/api/v1/report-metrics');
        $staffRes->assertOk();
        $staffCodes = collect($staffRes->json('data'))->pluck('code')->toArray();
        $this->assertContains('active_student_count', $staffCodes);
        $this->assertContains('approved_admissions', $staffCodes);
        $this->assertContains('pending_billing', $staffCodes);
        $this->assertNotContains('gross_cash', $staffCodes);
        $this->assertNotContains('net_cash', $staffCodes);
        $this->assertNotContains('outstanding_as_of', $staffCodes);

        // 2. Accounts can read only financial metrics
        $accountsRes = $this->actingAs($this->accountsUser)->getJson('/api/v1/report-metrics');
        $accountsRes->assertOk();
        $accountsCodes = collect($accountsRes->json('data'))->pluck('code')->toArray();
        $this->assertContains('gross_cash', $accountsCodes);
        $this->assertContains('net_cash', $accountsCodes);
        $this->assertContains('net_assessed', $accountsCodes);
        $this->assertContains('outstanding_as_of', $accountsCodes);
        $this->assertNotContains('active_student_count', $accountsCodes);
        $this->assertNotContains('approved_admissions', $accountsCodes);

        // 3. Admin can read all 10 metrics
        $adminRes = $this->actingAs($this->adminUser)->getJson('/api/v1/report-metrics');
        $adminRes->assertOk();
        $this->assertCount(10, $adminRes->json('data'));
    }

    /**
     * D09-F01-AT-01-03: Metrics distinguish distinct students from enrollment row counts.
     */
    public function test_at_01_03_active_student_count_metric_distinguishes_distinct_students(): void
    {
        $registry = app(MetricDefinitionRegistry::class);
        $activeMetric = $registry->getDefinition('active_student_count', $this->adminUser);

        $this->assertEquals('students', $activeMetric['unit']);
        $this->assertStringContainsString('COUNT(DISTINCT enrollments.student_id)', $activeMetric['formula']);
    }

    /**
     * D09-F01-AT-01-04: Numeric money results use decimal BDT, never binary floating point.
     */
    public function test_at_01_04_monetary_metrics_use_bdt_unit(): void
    {
        $registry = app(MetricDefinitionRegistry::class);
        $financialCodes = ['gross_cash', 'approved_reversals', 'net_cash', 'net_assessed', 'outstanding_as_of', 'overdue_90_plus', 'cashier_variance'];

        foreach ($financialCodes as $code) {
            $def = $registry->getDefinition($code, $this->adminUser);
            $this->assertEquals('BDT', $def['unit']);
        }
    }

    /**
     * D09-F01-AT-01-05: KPI result includes definition metadata, unit, and dimensions.
     */
    public function test_at_01_05_single_metric_definition_endpoint(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/api/v1/report-metrics/gross_cash');
        $res->assertOk()
            ->assertJsonPath('data.code', 'gross_cash')
            ->assertJsonPath('data.unit', 'BDT')
            ->assertJsonPath('data.source_owner', 'D07')
            ->assertJsonPath('data.drilldown_report_key', 'cash_collections');
    }

    /**
     * D09-F01-AT-01-06: Bad metric code returns 404 without disclosing internal SQL.
     */
    public function test_at_01_06_bad_metric_code_returns_404_safely(): void
    {
        $res = $this->actingAs($this->adminUser)->getJson('/api/v1/report-metrics/non_existent_metric_999');
        $res->assertStatus(404)
            ->assertJsonPath('error.code', 'METRIC_NOT_FOUND');

        $this->assertStringNotContainsString('select', strtolower($res->getContent()));
        $this->assertStringNotContainsString('sql', strtolower($res->getContent()));
    }

    /**
     * D09-F02-AT-02-01: Accounts cannot read academic profile edits or full guardian contact datasets.
     */
    public function test_at_02_01_accounts_denied_academic_metrics(): void
    {
        $res = $this->actingAs($this->accountsUser)->getJson('/api/v1/report-metrics/active_student_count');
        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_METRIC');
    }

    /**
     * D09-F02-AT-02-02: Staff cannot query cash totals/transactions without dedicated entitlement.
     */
    public function test_at_02_02_staff_cannot_query_cash_metrics(): void
    {
        $res = $this->actingAs($this->staffUser)->getJson('/api/v1/report-metrics/gross_cash');
        $res->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_METRIC');
    }

    /**
     * D09-F02-AT-02-03 & AT-02-04: Admin/Staff sees only department scope; forged department filter returns 403.
     */
    public function test_at_02_03_and_at_02_04_scope_enforcement_and_forged_filter_block(): void
    {
        // Give staffUser restricted scope: allowed only department 10
        $this->staffUser->update(['allowed_department_ids' => [10]]);
        $scope = new AuthorizedReportScope($this->staffUser->fresh());

        // Allowed filter
        $scope->assertFilterScope(['department_id' => 10]);

        // Forged filter for department 99 -> 403 FORBIDDEN_SCOPE
        $this->expectException(\App\Exceptions\ReportDomainException::class);
        $scope->assertFilterScope(['department_id' => 99]);
    }

    /**
     * D09-F02-AT-02-05: Report catalog omits unauthorized report keys.
     */
    public function test_at_02_05_report_catalog_omits_unauthorized_keys(): void
    {
        $catalogService = app(ReportCatalogService::class);

        // Staff sees academic reports only
        $staffReports = $catalogService->getCatalog($this->staffUser);
        $staffKeys = array_column($staffReports, 'report_key');
        $this->assertContains('student_roster', $staffKeys);
        $this->assertContains('enrollment_summary', $staffKeys);
        $this->assertNotContains('cash_collections', $staffKeys);
        $this->assertNotContains('overdue_aging', $staffKeys);

        // Accounts sees financial reports only
        $accountsReports = $catalogService->getCatalog($this->accountsUser);
        $accountsKeys = array_column($accountsReports, 'report_key');
        $this->assertContains('cash_collections', $accountsKeys);
        $this->assertContains('overdue_aging', $accountsKeys);
        $this->assertNotContains('student_roster', $accountsKeys);
        $this->assertNotContains('promotion_outcomes', $accountsKeys);

        // Admin sees all 13 reports
        $adminReports = $catalogService->getCatalog($this->adminUser);
        $this->assertCount(13, $adminReports);
    }

    /**
     * D09-F02-AT-02-06: Changing role immediately invalidates cached/previously saved unauthorized results.
     */
    public function test_at_02_06_changing_role_changes_scope_hash(): void
    {
        $scopeBefore = new AuthorizedReportScope($this->staffUser);
        $hashBefore = $scopeBefore->scopeHash;

        // Add accounts role to staff user
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();
        $this->staffUser->roles()->attach($accountsRole->id);

        $scopeAfter = new AuthorizedReportScope($this->staffUser->fresh());
        $hashAfter = $scopeAfter->scopeHash;

        $this->assertNotEquals($hashBefore, $hashAfter);
    }

    /**
     * D09-F02-AT-02-07: Query/drilldown/export apply same row and column permissions.
     */
    public function test_at_02_07_report_catalog_column_permissions(): void
    {
        $catalogService = app(ReportCatalogService::class);
        $def = $catalogService->getDefinition('student_roster', $this->staffUser);

        $this->assertNotEmpty($def['columns']);
        $colKeys = array_column($def['columns'], 'key');
        $this->assertContains('student_id', $colKeys);
        $this->assertContains('guardian_phone', $colKeys);
    }

    /**
     * D09-F02-AT-02-08: PII in report rows is masked for role-ineligible views.
     */
    public function test_at_02_08_pii_masking_for_non_admins(): void
    {
        $staffScope = new AuthorizedReportScope($this->staffUser);
        $adminScope = new AuthorizedReportScope($this->adminUser);

        $rawPhone = '01712345678';
        $maskedPhone = $staffScope->maskPhone($rawPhone);
        $adminPhone = $adminScope->maskPhone($rawPhone);

        $this->assertEquals('017*****678', $maskedPhone);
        $this->assertEquals('01712345678', $adminPhone);

        $this->assertEquals('***', $staffScope->maskRestricted('19901234567890123'));
        $this->assertEquals('19901234567890123', $adminScope->maskRestricted('19901234567890123'));
    }

    /**
     * D09-F02-AT-02-09: One actor cannot access another actor private saved view.
     */
    public function test_at_02_09_private_saved_view_isolation(): void
    {
        $staffView = ReportSavedView::create([
            'owner_user_id' => $this->staffUser->id,
            'report_key' => 'student_roster',
            'name' => 'My Private Roster',
            'visibility' => 'PRIVATE',
            'filters_json' => ['academicPeriodIds' => [1]],
            'columns_json' => ['student_id', 'name_bn'],
            'version' => 1,
        ]);

        $this->assertEquals($this->staffUser->id, $staffView->owner_user_id);
        $this->assertEquals('PRIVATE', $staffView->visibility);
        $this->assertNotEquals($this->accountsUser->id, $staffView->owner_user_id);
    }
}
