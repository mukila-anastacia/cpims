<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
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

    private function body(array $over = []): array
    {
        return array_merge([
            'branch_id' => Branch::where('name', 'Kasarani')->value('id'),
            'category_id' => Category::where('name', 'Batteries')->value('id'),
            'period_start' => '2027-01-01',
            'period_end' => '2027-03-31',
            'amount' => 250000,
        ], $over);
    }

    public function test_finance_creates_and_updates_a_budget_and_it_is_audited(): void
    {
        $finance = $this->user('finance');

        $id = $this->actingAs($finance)->postJson('/api/budgets', $this->body())
            ->assertCreated()->assertJsonPath('data.amount', 250000)->assertJsonPath('data.current', false)->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'BUDGET_CREATED']);

        $this->actingAs($finance)->putJson("/api/budgets/$id", $this->body(['amount' => 300000]))
            ->assertOk()->assertJsonPath('data.amount', 300000);

        $log = AuditLog::where('action', 'BUDGET_UPDATED')->latest('id')->first();
        $this->assertEquals(250000, $log->old_values['amount']);
        $this->assertEquals(300000, $log->new_values['amount']);
    }

    public function test_overlapping_periods_are_refused(): void
    {
        $finance = $this->user('finance');
        $this->actingAs($finance)->postJson('/api/budgets', $this->body())->assertCreated();

        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['period_start' => '2027-02-01', 'period_end' => '2027-04-30']))
            ->assertStatus(422)->assertJsonValidationErrors(['period_start']);
        // The seeded budget already covers October to December 2026
        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['period_start' => '2026-12-01', 'period_end' => '2027-01-15']))
            ->assertStatus(422);
        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['period_start' => '2027-04-01', 'period_end' => '2027-06-30']))
            ->assertCreated();
    }

    public function test_invalid_input_is_refused(): void
    {
        $finance = $this->user('finance');

        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['amount' => 0]))->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['period_end' => '2026-12-31']))->assertStatus(422)->assertJsonValidationErrors(['period_end']);
        $this->actingAs($finance)->postJson('/api/budgets', $this->body(['branch_id' => 9999]))->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
    }

    public function test_only_finance_changes_budgets_and_head_office_can_view(): void
    {
        $this->actingAs($this->user('procurement'))->postJson('/api/budgets', $this->body())->assertForbidden();
        $this->actingAs($this->user('headoffice'))->postJson('/api/budgets', $this->body())->assertForbidden();
        $this->actingAs($this->user('headoffice'))->getJson('/api/budgets')->assertOk();
        $this->actingAs($this->user('finance'))->getJson('/api/budgets')->assertOk();
        $this->actingAs($this->user('amina'))->getJson('/api/budgets')->assertForbidden();
        $this->actingAs($this->user('manager.kasarani'))->getJson('/api/budgets')->assertForbidden();
    }

    public function test_committed_and_remaining_reflect_approved_requests(): void
    {
        $kasarani = Branch::where('name', 'Kasarani')->value('id');
        $category = Category::where('name', 'Batteries')->value('id');
        $amina = $this->user('amina');

        PurchaseRequest::create(['request_no' => 'T-0001', 'branch_id' => $kasarani, 'category_id' => $category, 'requester_id' => $amina->id,
            'status' => 'approved', 'total_estimate' => 100000, 'submitted_at' => now()]);
        PurchaseRequest::create(['request_no' => 'T-0002', 'branch_id' => $kasarani, 'category_id' => $category, 'requester_id' => $amina->id,
            'status' => 'in_approval', 'total_estimate' => 50000, 'submitted_at' => now()]); // not counted yet

        $rows = $this->actingAs($this->user('finance'))->getJson('/api/budgets')->json('data');
        $row = collect($rows)->first(fn ($b) => $b['branch_id'] == $kasarani && $b['category_id'] == $category);

        $this->assertEquals(500000, $row['amount']);
        $this->assertEquals(100000, $row['committed']);
        $this->assertEquals(400000, $row['remaining']);
        $this->assertEquals(20, $row['used_pct']);
    }

    public function test_the_current_filter_hides_other_periods(): void
    {
        $finance = $this->user('finance');
        $this->actingAs($finance)->postJson('/api/budgets', $this->body())->assertCreated(); // 2027, not current

        $this->actingAs($finance)->getJson('/api/budgets')->assertOk()->assertJsonCount(45, 'data');
        $this->actingAs($finance)->getJson('/api/budgets?period=all')->assertOk()->assertJsonCount(46, 'data');
        $this->actingAs($finance)->getJson('/api/budgets?period=all&branch_id=' . Branch::where('name', 'Kasarani')->value('id'))
            ->assertOk()->assertJsonCount(6, 'data');
    }
}