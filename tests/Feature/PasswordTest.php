<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Workflow;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase, Workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function change(array $over = [])
    {
        return $this->actingAs($this->who('amina'))->postJson('/api/me/password', array_merge([
            'current_password' => 'rana@2026', 'password' => 'Fresh2027pass', 'password_confirmation' => 'Fresh2027pass',
        ], $over));
    }

    public function test_a_user_can_change_their_own_password(): void
    {
        $this->change()->assertOk();

        $this->postJson('/api/login', ['username' => 'amina', 'password' => 'rana@2026'])->assertStatus(401);
        $this->postJson('/api/login', ['username' => 'amina', 'password' => 'Fresh2027pass'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_CHANGED', 'user_id' => $this->who('amina')->id]);
    }

    public function test_the_current_password_must_be_right(): void
    {
        $this->change(['current_password' => 'wrong-one'])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_CHANGE_FAILED']);
        $this->postJson('/api/login', ['username' => 'amina', 'password' => 'rana@2026'])->assertOk();
    }

    public function test_weak_repeated_and_mismatched_passwords_are_refused(): void
    {
        $this->change(['password' => 'short1', 'password_confirmation' => 'short1'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->change(['password' => 'rana@2026', 'password_confirmation' => 'rana@2026'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->change(['password_confirmation' => 'Different2027'])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_other_sessions_of_the_user_are_ended(): void
    {
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $this->who('amina')->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);

        $this->change()->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('id', 'old-session')->count());
    }

    public function test_password_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->change(['current_password' => 'wrong-one'])->assertStatus(422);
        }

        $this->change(['current_password' => 'wrong-one'])->assertStatus(429);
    }

    public function test_changing_a_password_needs_a_login(): void
    {
        $this->postJson('/api/me/password', ['current_password' => 'rana@2026', 'password' => 'Fresh2027pass', 'password_confirmation' => 'Fresh2027pass'])->assertStatus(401);
    }
}