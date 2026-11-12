<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->first();
    }

    public function test_only_admin_and_head_office_can_read_the_log(): void
    {
        foreach (['admin', 'headoffice'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/audit-logs')->assertOk();
        }
        foreach (['finance', 'procurement', 'amina', 'manager.kasarani'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/audit-logs')->assertForbidden();
        }
    }

    public function test_the_log_is_paged_and_filtered_by_action(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            AuditService::log('TEST_ACTION', 'test', $i);
        }
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/audit-logs?action=TEST_ACTION')->assertOk()
            ->assertJsonPath('total', 30)->assertJsonPath('pages', 2)->assertJsonCount(25, 'data');
        $this->actingAs($admin)->getJson('/api/audit-logs?action=TEST_ACTION&page=2')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_the_log_can_be_filtered_by_user_and_date(): void
    {
        AuditService::log('ONE', 'test', 1, null, null, $this->user('amina')->id);
        AuditService::log('TWO', 'test', 2, null, null, $this->user('john')->id);
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/audit-logs?user_id=' . $this->user('amina')->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.action', 'ONE');
        $this->actingAs($admin)->getJson('/api/audit-logs?from=' . now()->addDay()->toDateString())->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($admin)->getJson('/api/audit-logs?to=' . now()->toDateString())->assertOk()->assertJsonPath('total', 2);
    }

    public function test_entries_show_who_did_what_with_old_and_new_values(): void
    {
        AuditService::log('DETAIL', 'test', 5, ['a' => 1], ['a' => 2], $this->user('amina')->id);

        $this->actingAs($this->user('headoffice'))->getJson('/api/audit-logs?action=DETAIL')->assertOk()
            ->assertJsonPath('data.0.user', 'Amina Wafula')
            ->assertJsonPath('data.0.old.a', 1)
            ->assertJsonPath('data.0.new.a', 2)
            ->assertJsonPath('data.0.entity_id', 5);
    }
}