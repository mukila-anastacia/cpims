<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\RequestLine;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
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

    private function raise(string $item, float $qty = 1, float $cost = 20000): int
    {
        $amina = $this->user('amina');
        $it = Item::where('name', $item)->first();
        $id = $this->actingAs($amina)->postJson('/api/requests', [
            'category_id' => $it->category_id,
            'needed_by' => now()->addDays(7)->toDateString(),
            'justification' => 'Needed for a customer installation',
            'lines' => [['item_id' => $it->id, 'quantity' => $qty, 'unit_cost_est' => $cost]],
        ])->json('data.id');
        $this->actingAs($amina)->postJson("/api/requests/$id/submit", ['duplicate_justification' => 'Needed again for another job'])->assertOk();

        return $id;
    }

    private function approve(int $id): void
    {
        $this->actingAs($this->user('manager.kasarani'))->postJson("/api/requests/$id/decision", ['decision' => 'approve'])->assertOk();
    }

    private function order(int $id): int
    {
        $proc = $this->user('procurement');
        $lines = RequestLine::where('purchase_request_id', $id)->get();
        $this->actingAs($proc)->postJson("/api/requests/$id/quotations", [
            'vendor_id' => Vendor::where('tax_pin', 'P000000001A')->value('id'),
            'delivery_days' => 5,
            'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => $lines->map(fn ($l) => ['request_line_id' => $l->id, 'unit_price' => 19000])->all(),
        ])->assertCreated();
        $qid = Quotation::where('purchase_request_id', $id)->value('id');
        $this->actingAs($proc)->postJson("/api/requests/$id/quotations/$qid/select")->assertOk();

        return $this->actingAs($proc)->postJson("/api/requests/$id/order")->assertCreated()->json('data.id');
    }

    private function card($response, string $key): ?int
    {
        return collect($response->json('cards'))->firstWhere('key', $key)['value'] ?? null;
    }

    public function test_dashboard_cards_depend_on_the_role(): void
    {
        $this->raise('Solar cable 6mm');

        $this->assertSame(1, $this->card($this->actingAs($this->user('manager.kasarani'))->getJson('/api/dashboard')->assertOk(), 'pending_approvals'));
        $this->assertSame(0, $this->card($this->actingAs($this->user('manager.karen'))->getJson('/api/dashboard')->assertOk(), 'pending_approvals'));
        $this->assertSame(1, $this->card($this->actingAs($this->user('amina'))->getJson('/api/dashboard')->assertOk(), 'in_approval'));

        $activeUsers = User::where('status', 'active')->count();
        $this->assertSame($activeUsers, $this->card($this->actingAs($this->user('admin'))->getJson('/api/dashboard')->assertOk(), 'active_users'));
        $this->assertNull($this->card($this->actingAs($this->user('admin'))->getJson('/api/dashboard'), 'pending_approvals'));
    }

    public function test_indicators_are_calculated_from_the_workflow(): void
    {
        $a = $this->raise('Solar cable 6mm');          // approved and ordered
        $this->approve($a);
        $this->order($a);
        $this->raise('Solar cable 6mm');               // second request for the same item: duplicate flag
        $gel = Item::where('name', 'Gel battery 100Ah')->first();
        Budget::where('branch_id', $this->user('amina')->branch_id)->where('category_id', $gel->category_id)->update(['amount' => 10000]);
        $this->raise('Gel battery 100Ah');             // above the remaining budget: exception

        $res = $this->actingAs($this->user('finance'))->getJson('/api/reports/summary')->assertOk();

        $res->assertJsonPath('indicators.submitted', 3)
            ->assertJsonPath('indicators.approval_turnaround.count', 1)
            ->assertJsonPath('indicators.request_to_order.count', 1)
            ->assertJsonPath('indicators.budget_compliance.exceptions', 1)
            ->assertJsonPath('indicators.budget_compliance.within', 2)
            ->assertJsonPath('indicators.budget_compliance.pct', 66.7)
            ->assertJsonPath('indicators.duplicates.flagged', 1)
            ->assertJsonPath('indicators.duplicates.pct', 33.3)
            ->assertJsonPath('by_status.ordered', 1)
            ->assertJsonPath('by_status.in_approval', 2)
            ->assertJsonPath('ageing.d0_1', 2);

        $this->assertNotNull($res->json('indicators.approval_turnaround.avg_hours'));
        $this->assertGreaterThanOrEqual(0, $res->json('indicators.reporting_time_ms'));
        $this->assertStringContainsString('2 to 4 working days', $res->json('baseline.approval_turnaround'));
    }

    public function test_spend_is_taken_from_issued_orders(): void
    {
        $id = $this->raise('Solar cable 6mm');
        $this->approve($id);
        $this->order($id);

        $this->actingAs($this->user('headoffice'))->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('spend_by_vendor.0.name', 'SunTech Distributors Ltd')
            ->assertJsonPath('spend_by_vendor.0.total', 19000)
            ->assertJsonPath('spend_by_branch.0.name', 'Kasarani')
            ->assertJsonPath('spend_by_branch.0.total', 19000);
    }

    public function test_branch_managers_only_see_their_own_branch(): void
    {
        $this->raise('Solar cable 6mm');
        $kasarani = $this->user('amina')->branch_id;
        $karen = $this->user('john')->branch_id;

        $this->actingAs($this->user('manager.kasarani'))->getJson('/api/reports/summary')->assertOk()->assertJsonPath('indicators.submitted', 1);
        $this->actingAs($this->user('manager.karen'))->getJson('/api/reports/summary')->assertOk()->assertJsonPath('indicators.submitted', 0);
        // Asking for another branch is ignored
        $this->actingAs($this->user('manager.karen'))->getJson("/api/reports/summary?branch_id=$kasarani")->assertOk()->assertJsonPath('indicators.submitted', 0);
        // Finance can choose a branch
        $this->actingAs($this->user('finance'))->getJson("/api/reports/summary?branch_id=$karen")->assertOk()->assertJsonPath('indicators.submitted', 0);
        $this->actingAs($this->user('finance'))->getJson('/api/reports/summary')->assertOk()->assertJsonPath('indicators.submitted', 1);
    }

    public function test_only_the_right_roles_can_open_reports(): void
    {
        foreach (['procurement', 'finance', 'headoffice', 'manager.kasarani'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/reports/summary')->assertOk();
        }
        foreach (['amina', 'store.kasarani', 'admin'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/reports/summary')->assertForbidden();
        }
    }

    public function test_csv_export_contains_the_rows_and_is_audited(): void
    {
        $this->raise('Solar cable 6mm');

        $res = $this->actingAs($this->user('finance'))->get('/api/reports/export?type=requests')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('Request no', $csv);
        $this->assertStringContainsString('PR-0001', $csv);
        $this->assertStringContainsString('Kasarani', $csv);
        $this->assertStringContainsString('Amina Wafula', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_EXPORTED', 'user_id' => $this->user('finance')->id]);
    }

    public function test_csv_cells_that_look_like_formulas_are_neutralised(): void
    {
        User::where('username', 'amina')->update(['full_name' => '=1+1']);
        $this->raise('Solar cable 6mm');

        $csv = $this->actingAs($this->user('finance'))->get('/api/reports/export?type=requests')->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString(',=1+1', $csv);
    }

    public function test_export_permissions_and_types(): void
    {
        $this->actingAs($this->user('manager.kasarani'))->get('/api/reports/export?type=invoices')->assertForbidden();
        $this->actingAs($this->user('manager.kasarani'))->get('/api/reports/export?type=orders')->assertOk();
        $this->actingAs($this->user('finance'))->get('/api/reports/export?type=invoices')->assertOk();
        $this->actingAs($this->user('finance'))->getJson('/api/reports/export?type=bogus')->assertStatus(422);
        $this->actingAs($this->user('amina'))->get('/api/reports/export?type=requests')->assertForbidden();
    }
}