<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_valid_login_returns_user_and_is_audited(): void
    {
        $this->postJson('/api/login', ['username' => 'amina', 'password' => 'Password@123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'Requester')
            ->assertJsonPath('user.branch', 'Kasarani');

        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN', 'entity' => 'user']);
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $this->postJson('/api/login', ['username' => 'amina', 'password' => 'wrong'])->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_FAILED']);
    }

    public function test_account_locks_after_five_failed_logins(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => 'john', 'password' => 'wrong']);
        }

        $this->postJson('/api/login', ['username' => 'john', 'password' => 'Password@123'])->assertStatus(423);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACCOUNT_LOCKED']);
    }

    public function test_me_requires_login(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::where('username', 'amina')->first();
        $this->actingAs($user)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGOUT', 'user_id' => $user->id]);
    }

    public function test_role_check_blocks_other_roles_and_is_audited(): void
    {
        $requester = User::where('username', 'amina')->first();
        $admin = User::where('username', 'admin')->first();

        $this->actingAs($requester)->getJson('/api/admin/ping')->assertStatus(403);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACCESS_DENIED', 'user_id' => $requester->id]);

        $this->actingAs($admin)->getJson('/api/admin/ping')->assertOk();
    }

    public function test_audit_log_cannot_be_changed_or_deleted(): void
    {
        $log = AuditService::log('TEST_ACTION', 'test');

        $log->update(['action' => 'CHANGED']);
        $log->delete();

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'TEST_ACTION']);
        $this->assertSame(1, AuditLog::where('action', 'TEST_ACTION')->count());
    }
}