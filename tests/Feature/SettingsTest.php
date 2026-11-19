<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\Workflow;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase, Workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->value('id');
    }

    private function settingsBody(array $over = []): array
    {
        return array_merge(['duplicate_window_days' => 14, 'price_tolerance_pct' => 2, 'min_quotations' => 3, 'quotation_threshold' => 100000, 'lockout_attempts' => 5], $over);
    }

    private function approvalBody(array $over = []): array
    {
        return array_merge([
            'level2_min' => 50000, 'level2_role_id' => $this->roleId('Procurement Administrator'),
            'level3_enabled' => true, 'level3_min' => 200000, 'level3_role_id' => $this->roleId('Finance Officer'),
        ], $over);
    }

    private function approvalCount(int $requestId): int
    {
        return Approval::where('purchase_request_id', $requestId)->count();
    }

    public function test_the_administrator_and_head_office_can_read_the_settings(): void
    {
        $this->actingAs($this->who('admin'))->getJson('/api/settings')->assertOk()
            ->assertJsonPath('can_edit', true)
            ->assertJsonCount(5, 'settings')
            ->assertJsonPath('approval.level2_min', 50000)
            ->assertJsonPath('approval.level3_min', 200000)
            ->assertJsonPath('approval.level3_enabled', true);
        $this->actingAs($this->who('headoffice'))->getJson('/api/settings')->assertOk()->assertJsonPath('can_edit', false);

        foreach (['finance', 'procurement', 'amina', 'manager.kasarani'] as $name) {
            $this->actingAs($this->who($name))->getJson('/api/settings')->assertForbidden();
        }
    }

    public function test_only_the_administrator_can_change_settings(): void
    {
        $this->actingAs($this->who('headoffice'))->putJson('/api/settings', $this->settingsBody())->assertForbidden();
        $this->actingAs($this->who('headoffice'))->putJson('/api/approval-rules', $this->approvalBody())->assertForbidden();
        $this->actingAs($this->who('finance'))->putJson('/api/settings', $this->settingsBody())->assertForbidden();

        $this->actingAs($this->who('admin'))->putJson('/api/settings', $this->settingsBody())->assertOk();
        $this->actingAs($this->who('admin'))->putJson('/api/approval-rules', $this->approvalBody())->assertOk();
    }

    public function test_setting_values_are_validated(): void
    {
        $admin = $this->who('admin');

        foreach ([['duplicate_window_days', 0], ['duplicate_window_days', 120], ['price_tolerance_pct', 50], ['min_quotations', 0], ['quotation_threshold', -1], ['lockout_attempts', 2]] as [$key, $value]) {
            $this->actingAs($admin)->putJson('/api/settings', $this->settingsBody([$key => $value]))
                ->assertStatus(422)->assertJsonValidationErrors([$key]);
        }
    }

    public function test_changes_are_saved_audited_and_used(): void
    {
        $admin = $this->who('admin');

        $this->actingAs($admin)->putJson('/api/settings', $this->settingsBody(['lockout_attempts' => 3, 'duplicate_window_days' => 30]))->assertOk();

        $this->assertSame('3', Setting::get('lockout_attempts'));
        $this->assertSame('30', Setting::get('duplicate_window_days'));
        $log = AuditLog::where('action', 'SETTINGS_UPDATED')->latest('id')->first();
        $this->assertEquals(5, $log->old_values['lockout_attempts']);
        $this->assertEquals(3, $log->new_values['lockout_attempts']);
        $this->assertEquals(30, $log->new_values['duplicate_window_days']);

        // Saving the same values again records nothing new
        $this->actingAs($admin)->putJson('/api/settings', $this->settingsBody(['lockout_attempts' => 3, 'duplicate_window_days' => 30]))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'SETTINGS_UPDATED')->count());

        // The new lock-out limit is the one in force
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['username' => 'john', 'password' => 'wrong'])->assertStatus(401);
        }
        $this->postJson('/api/login', ['username' => 'john', 'password' => 'rana@2026'])->assertStatus(423);
    }

    public function test_the_duplicate_window_is_used(): void
    {
        $john = $this->raise('john', 'Solar cable 6mm', 1, 20000);
        PurchaseRequest::where('id', $john)->update(['created_at' => now()->subDays(5)]);

        $item = Item::where('name', 'Solar cable 6mm')->first();
        $amina = $this->who('amina');
        $draft = $this->actingAs($amina)->postJson('/api/requests', [
            'category_id' => $item->category_id, 'justification' => 'Needed',
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost_est' => 20000]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($amina)->postJson("/api/requests/$draft/submit")->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_JUSTIFICATION_REQUIRED');

        $this->actingAs($this->who('admin'))->putJson('/api/settings', $this->settingsBody(['duplicate_window_days' => 3]))->assertOk();

        $this->actingAs($amina)->postJson("/api/requests/$draft/submit")->assertOk();
    }

    public function test_changing_the_approval_limits_changes_the_routing(): void
    {
        $this->assertSame(1, $this->approvalCount($this->raise('amina', 'Solar cable 6mm', 1, 20000)));

        $this->actingAs($this->who('admin'))->putJson('/api/approval-rules', $this->approvalBody(['level2_min' => 10000, 'level3_min' => 30000]))->assertOk()
            ->assertJsonPath('approval.level2_min', 10000)->assertJsonPath('approval.level3_min', 30000);

        $this->assertSame(2, $this->approvalCount($this->raise('amina', 'Battery cable 35mm', 1, 20000)));
        $this->assertSame(3, $this->approvalCount($this->raise('amina', 'Mono solar panel 450W', 1, 40000)));
    }

    public function test_limits_are_validated_and_level_three_can_be_turned_off(): void
    {
        $admin = $this->who('admin');

        $this->actingAs($admin)->putJson('/api/approval-rules', $this->approvalBody(['level3_min' => 40000, 'level2_min' => 50000]))
            ->assertStatus(422)->assertJsonValidationErrors(['level3_min']);
        $this->actingAs($admin)->putJson('/api/approval-rules', $this->approvalBody(['level3_role_id' => $this->roleId('Procurement Administrator')]))
            ->assertStatus(422)->assertJsonValidationErrors(['level3_role_id']);
        $this->actingAs($admin)->putJson('/api/approval-rules', $this->approvalBody(['level2_role_id' => $this->roleId('Requester')]))
            ->assertStatus(422)->assertJsonValidationErrors(['level2_role_id']);
        $this->actingAs($admin)->putJson('/api/approval-rules', $this->approvalBody(['level2_min' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['level2_min']);

        $this->actingAs($admin)->putJson('/api/approval-rules', $this->approvalBody(['level3_enabled' => false, 'level3_min' => null, 'level3_role_id' => null]))
            ->assertOk()->assertJsonPath('approval.level3_enabled', false);

        $this->assertSame(2, $this->approvalCount($this->raise('amina', 'Mono solar panel 450W', 1, 300000)));
    }

    public function test_the_roles_for_each_level_can_be_changed(): void
    {
        $this->actingAs($this->who('admin'))->putJson('/api/approval-rules', $this->approvalBody([
            'level2_role_id' => $this->roleId('Head Office Manager'), 'level3_role_id' => $this->roleId('Procurement Administrator'),
        ]))->assertOk();

        $id = $this->raise('amina', 'Mono solar panel 450W', 1, 300000);
        $approvers = Approval::where('purchase_request_id', $id)->orderBy('level')->get()->map(fn ($a) => $a->approver->username)->all();

        $this->assertSame(['manager.kasarani', 'headoffice', 'procurement'], $approvers);
        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVAL_LIMITS_UPDATED']);
    }
}