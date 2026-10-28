<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class DeliverableOneComprehensiveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_auth_01_and_02_login_valid_and_invalid_flow(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'admin.user',
            '--email' => 'admin.user@example.com',
            '--password' => 'AdminPass12345!',
        ]);

        // Invalid credentials
        $invalid = $this->postJson('/login', [
            'login' => 'admin.user',
            'password' => 'WrongPassword!',
        ]);
        $invalid->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        // Valid credentials
        $valid = $this->postJson('/login', [
            'login' => 'admin.user',
            'password' => 'AdminPass12345!',
        ]);
        $valid->assertNoContent();

        // Get me
        $me = $this->getJson('/api/v1/me');
        $me->assertOk()
            ->assertJsonPath('data.username', 'admin.user')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.roles.0', 'ADMIN');
    }

    public function test_auth_05_staff_cannot_access_user_management(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'sys.admin',
            '--email' => 'sys.admin@example.com',
            '--password' => 'AdminPass12345!'
        ]);
        $admin = User::where('username', 'sys.admin')->first();

        // Create staff user
        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name_bn' => 'স্টাফ ইউজার',
            'name_en' => 'Staff User',
            'username' => 'staff.user',
            'role' => 'OFFICE_STAFF',
        ]);
        $createRes->assertStatus(201);
        $token = $createRes->json('data.invitation.activation_token');

        // Activate staff
        $this->postJson('/api/v1/auth/activate', [
            'token' => $token,
            'password' => 'StaffPass12345!',
            'password_confirmation' => 'StaffPass12345!',
        ])->assertOk();

        $staff = User::where('username', 'staff.user')->first();

        // Staff attempts hitting admin user management
        $denied = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/admin/users');
        $denied->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_auth_12_cannot_disable_last_active_admin(): void
    {
        // Find all active admins
        $activeAdmins = User::where('status', 'ACTIVE')
            ->whereHas('roles', fn ($q) => $q->where('key', 'ADMIN'))
            ->get();

        // Ensure there is an active admin
        if ($activeAdmins->isEmpty()) {
            $this->artisan('mms:admin:bootstrap', [
                '--username' => 'sole.admin',
                '--email' => 'sole.admin@example.com',
                '--password' => 'AdminPass12345!'
            ]);
            $admin = User::where('username', 'sole.admin')->first();
        } else {
            // Keep only the first active admin and disable any other active admins
            $admin = $activeAdmins->shift();
            foreach ($activeAdmins as $other) {
                $other->update(['status' => 'DISABLED']);
            }
        }

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/admin/users/{$admin->public_id}/status", [
            'status' => 'DISABLED',
            'reason' => 'Testing lockout prevention',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_auth_13_user_self_escalation_attempt_is_rejected(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'root.admin',
            '--email' => 'root.admin@example.com',
            '--password' => 'AdminPass12345!'
        ]);
        $admin = User::where('username', 'root.admin')->first();

        $createRes = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name_bn' => 'অ্যাকাউন্টস ইউজার',
            'name_en' => 'Accounts User',
            'username' => 'acc.user',
            'role' => 'ACCOUNTS',
        ]);
        $token = $createRes->json('data.invitation.activation_token');
        $this->postJson('/api/v1/auth/activate', [
            'token' => $token,
            'password' => 'AccPass12345!',
            'password_confirmation' => 'AccPass12345!',
        ])->assertOk();

        $accUser = User::where('username', 'acc.user')->first();

        // User attempts to inject roles or status in self profile update
        $escalate = $this->actingAs($accUser, 'sanctum')->patchJson('/api/v1/me', [
            'name_en' => 'Accounts Hacker',
            'role' => 'ADMIN',
            'roles' => ['ADMIN'],
        ]);

        $escalate->assertStatus(403);
    }

    public function test_auth_14_and_15_change_password_flow(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'pw.admin',
            '--email' => 'pw.admin@example.com',
            '--password' => 'OldPassword123!'
        ]);
        $admin = User::where('username', 'pw.admin')->first();

        // Wrong current password
        $wrong = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/me/password', [
            'current_password' => 'IncorrectCurrent!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);
        $wrong->assertStatus(422);

        // Correct password change
        $success = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/me/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);
        $success->assertNoContent();
    }
}
