<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_auth_01_valid_active_credentials_can_login_and_fetch_me(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'test.admin',
            '--password' => 'AdminSecurePass123!',
            '--name-en' => 'Test Admin',
            '--name-bn' => 'টেস্ট অ্যাডমিন',
            '--email' => 'admin_test1@test.com',
        ])->assertSuccessful();

        $loginResponse = $this->postJson('/login', [
            'login' => 'test.admin',
            'password' => 'AdminSecurePass123!',
        ]);

        $loginResponse->assertNoContent();

        $meResponse = $this->getJson('/api/v1/me');
        $meResponse->assertOk()
            ->assertJsonPath('data.username', 'test.admin')
            ->assertJsonPath('data.roles.0', 'ADMIN');
    }

    public function test_auth_02_invalid_credentials_returns_generic_error(): void
    {
        $response = $this->postJson('/login', [
            'login' => 'nonexistent.user',
            'password' => 'InvalidPassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_auth_04_unauthenticated_admin_api_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/users');
        $response->assertStatus(401);
    }

    public function test_auth_06_admin_can_provision_invited_user_with_single_use_token(): void
    {
        $this->artisan('mms:admin:bootstrap', [
            '--username' => 'super.admin',
            '--email' => 'super.admin@test.com',
            '--password' => 'AdminSecurePass123!',
        ]);
        $admin = User::where('username', 'super.admin')->first();

        $randomUsername = 'sadiq.' . uniqid();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name_bn' => 'মোহাম্মদ সাদিক',
            'name_en' => 'Mohammad Sadiq',
            'username' => $randomUsername,
            'role' => 'OFFICE_STAFF',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.username', $randomUsername)
            ->assertJsonPath('data.status', 'INVITED')
            ->assertJsonPath('data.invitation.delivery', 'MANUAL_HANDOFF');

        $token = $response->json('data.invitation.activation_token');
        $this->assertNotEmpty($token);

        // Activate the account
        $activateResponse = $this->postJson('/api/v1/auth/activate', [
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $activateResponse->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');

        // Verify token cannot be reused
        $reuseResponse = $this->postJson('/api/v1/auth/activate', [
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $reuseResponse->assertStatus(409);
    }
}
