<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Workflow;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase, Workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_app_shell_sends_security_headers(): void
    {
        $res = $this->withoutVite()->get('/');

        $res->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertNull($res->headers->get('Strict-Transport-Security'));

        $this->withoutVite()->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_api_answers_are_not_cached(): void
    {
        $res = $this->actingAs($this->who('admin'))->getJson('/api/users')->assertOk();

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['username' => 'nobody' . $i, 'password' => 'wrong'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['username' => 'nobody10', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_unknown_usernames_get_the_same_answers_as_real_ones(): void
    {
        $real = $this->postJson('/api/login', ['username' => 'amina', 'password' => 'wrong-pass'])->assertStatus(401);
        $ghost = $this->postJson('/api/login', ['username' => 'nobody.here', 'password' => 'wrong-pass'])->assertStatus(401);
        $this->assertSame($real->json('message'), $ghost->json('message'));

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', ['username' => 'nobody.here', 'password' => 'wrong-pass'])->assertStatus(401);
        }

        $locked = $this->postJson('/api/login', ['username' => 'nobody.here', 'password' => 'wrong-pass'])->assertStatus(423);
        $this->assertStringContainsString('Account locked. Try again after', $locked->json('message'));
    }

    public function test_a_user_deactivated_while_logged_in_loses_access(): void
    {
        $john = $this->who('john');
        $this->actingAs($john)->getJson('/api/me')->assertOk();

        User::where('id', $john->id)->update(['status' => 'inactive']);

        $this->actingAs($john->fresh())->getJson('/api/me')->assertStatus(401);
    }

    public function test_resetting_a_password_ends_the_stored_sessions_of_that_user(): void
    {
        $john = $this->who('john');
        DB::table('sessions')->insert(['id' => 'session-john-1', 'user_id' => $john->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($this->who('admin'))->putJson("/api/users/{$john->id}", [
            'full_name' => $john->full_name, 'email' => $john->email, 'role_id' => $john->role_id,
            'branch_id' => $john->branch_id, 'status' => 'active', 'password' => 'NewPass2027',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $john->id)->count());
    }

    public function test_the_audit_log_is_protected_inside_the_database(): void
    {
        AuditService::log('TEST_ACTION', 'test');

        $attempts = [
            'change' => fn () => DB::table('audit_logs')->update(['action' => 'CHANGED']),
            'delete' => fn () => DB::table('audit_logs')->delete(),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail("The audit log could be modified by a raw query ($name).");
            } catch (QueryException $e) {
                $this->assertStringContainsString('audit log', $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TEST_ACTION')->count());
    }

    public function test_session_settings_are_safe(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame(15, (int) config('session.lifetime'));
    }

    public function test_the_api_is_rate_limited(): void
    {
        $user = $this->who('amina');

        for ($i = 0; $i < 240; $i++) {
            $this->actingAs($user)->getJson('/api/me')->assertOk();
        }

        $this->actingAs($user)->getJson('/api/me')->assertStatus(429);
    }

    public function test_extra_fields_cannot_set_protected_values(): void
    {
        $amina = $this->who('amina');
        $other = $this->who('manager.karen');
        $item = Item::where('name', 'Solar cable 6mm')->first();

        $id = $this->actingAs($amina)->postJson('/api/requests', [
            'category_id' => $item->category_id,
            'justification' => 'Needed',
            'lines' => [['item_id' => $item->id, 'quantity' => 10, 'unit_cost_est' => 2000]],
            'requester_id' => $other->id, 'branch_id' => $other->branch_id, 'status' => 'approved', 'current_level' => 3, 'total_estimate' => 1,
        ])->assertCreated()->json('data.id');

        $r = PurchaseRequest::find($id);
        $this->assertSame($amina->id, $r->requester_id);
        $this->assertSame($amina->branch_id, $r->branch_id);
        $this->assertSame('draft', $r->status);
        $this->assertEquals(20000, $r->total_estimate);
    }

    public function test_user_lists_never_contain_password_data(): void
    {
        $body = $this->actingAs($this->who('admin'))->getJson('/api/users')->assertOk()->getContent();

        $this->assertStringNotContainsString('password', strtolower($body));
        $this->assertStringNotContainsString('$2y$', $body);
    }
}