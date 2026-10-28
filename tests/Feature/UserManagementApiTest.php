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
}
