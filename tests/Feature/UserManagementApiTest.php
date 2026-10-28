<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_admin_can_view_update_and_manage_user_lifecycle(): void
    {
        // 1. Setup admin
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'lead.admin',
            '--email' => 'lead.admin@example.com',
            '--password' => 'LeadAdmin12345!',
        ]);
        $admin = User::where('username', 'lead.admin')->first();

        // 2. Provision target user
        $createRes = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name_bn' => 'মুহাম্মদ হাসান',
            'name_en' => 'Muhammad Hasan',
            'username' => 'hasan.office',
            'email' => 'hasan@example.com',
            'phone' => '+8801711223344',
            'role' => 'OFFICE_STAFF',
        ]);

        $createRes->assertCreated();
        $userId = $createRes->json('data.id');

        // 3. Admin can get user by public ID
        $getRes = $this->actingAs($admin)->getJson("/api/v1/admin/users/{$userId}");
        $getRes->assertOk()
            ->assertJsonPath('data.username', 'hasan.office')
            ->assertJsonPath('data.name_en', 'Muhammad Hasan')
            ->assertJsonPath('data.status', 'INVITED');

        // 4. Admin can update user details
        $updateRes = $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$userId}", [
            'name_en' => 'Muhammad Hasan Updated',
            'name_bn' => 'মুহাম্মদ হাসান (আপডেট)',
            'phone' => '+8801799887766',
        ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.name_en', 'Muhammad Hasan Updated')
            ->assertJsonPath('data.phone', '+8801799887766');

        // 5. Admin can update role to ACCOUNTS
        $roleRes = $this->actingAs($admin)->putJson("/api/v1/admin/users/{$userId}/roles", [
            'roles' => ['ACCOUNTS'],
        ]);
        $roleRes->assertOk()
            ->assertJsonPath('data.role', 'ACCOUNTS');

        // 6. Admin can reissue activation link
        $reissueRes = $this->actingAs($admin)->postJson("/api/v1/admin/users/{$userId}/invitations");
        $reissueRes->assertOk()
            ->assertJsonStructure(['data' => ['activation_url', 'expires_at']]);

        // 7. Non-admin cannot perform updates (forbidden)
        $staffUser = User::where('username', 'hasan.office')->first();
        $staffUser->update(['status' => AccountStatus::ACTIVE]);

        $forbiddenRes = $this->actingAs($staffUser)->patchJson("/api/v1/admin/users/{$userId}", [
            'name_en' => 'Hacker Name',
        ]);
        $forbiddenRes->assertForbidden();
    }

    public function test_admin_can_provision_user_with_manual_permission_flags_and_customize_roles(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'perm.admin',
            '--email' => 'perm.admin@example.com',
            '--password' => 'LeadAdmin12345!',
        ]);
        $admin = User::where('username', 'perm.admin')->first();

        // 1. Create a custom permission flag
        $permRes = $this->actingAs($admin)->postJson('/api/v1/admin/permissions', [
            'key' => 'library.books.issue',
            'module' => 'D10_Library',
            'label_en' => 'Issue Library Books',
            'label_bn' => 'লাইব্রেরি বই ইস্যু',
        ]);
        $permRes->assertCreated()
            ->assertJsonPath('data.key', 'library.books.issue');

        // 2. Create a custom role with custom permission
        $roleRes = $this->actingAs($admin)->postJson('/api/v1/admin/roles', [
            'key' => 'LIBRARIAN',
            'name_en' => 'Librarian Staff',
            'name_bn' => 'লাইব্রেরিয়ান',
            'permissions' => ['library.books.issue', 'profile.read'],
        ]);
        $roleRes->assertCreated()
            ->assertJsonPath('data.key', 'LIBRARIAN');

        // 3. Provision a user with manual custom permission flags
        $userRes = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name_bn' => 'কাস্টম ইউজার',
            'name_en' => 'Custom Flag User',
            'username' => 'custom.flag',
            'email' => 'custom.flag@example.com',
            'role' => 'LIBRARIAN',
            'permissions' => ['library.books.issue', 'students.read', 'reports.academic.read'],
        ]);
        $userRes->assertCreated();
        $userId = $userRes->json('data.id');

        // 4. Verify user has the permissions
        $createdUser = User::where('username', 'custom.flag')->first();
        $this->assertTrue($createdUser->hasPermission('library.books.issue'));
        $this->assertTrue($createdUser->hasPermission('students.read'));
        $this->assertTrue($createdUser->hasPermission('reports.academic.read'));

        // 5. Update user permission flags directly via PUT /v1/admin/users/{id}/permissions
        $updatePermRes = $this->actingAs($admin)->putJson("/api/v1/admin/users/{$userId}/permissions", [
            'permissions' => ['library.books.issue', 'payments.read'],
        ]);
        $updatePermRes->assertOk();
        $this->assertTrue($createdUser->fresh(['permissionGrants'])->hasPermission('payments.read'));
        $this->assertFalse($createdUser->fresh(['permissionGrants'])->permissionGrants->contains('key', 'students.read'));
    }
}
