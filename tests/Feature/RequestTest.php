<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Budget;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestTest extends TestCase
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

    private function payload(string $itemName, float $qty, float $cost): array
    {
        $item = Item::where('name', $itemName)->first();

        return [
            'category_id' => $item->category_id,
            'needed_by' => now()->addDays(7)->toDateString(),
            'justification' => 'Needed for a customer installation',
            'lines' => [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost_est' => $cost]],
        ];
    }

    private function create(User $user, string $item, float $qty, float $cost): int
    {
        return $this->actingAs($user)->postJson('/api/requests', $this->payload($item, $qty, $cost))
            ->assertCreated()->json('data.id');
    }

    public function test_requester_saves_a_draft_with_a_request_number(): void
    {
        $amina = $this->user('amina');
        $res = $this->actingAs($amina)->postJson('/api/requests', $this->payload('Solar cable 6mm', 10, 2000))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_estimate', 20000);

        $this->assertMatchesRegularExpression('/^PR-\d{4}$/', $res->json('data.request_no'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'REQUEST_CREATED']);
    }

    public function test_approval_levels_follow_the_request_value(): void
    {
        $amina = $this->user('amina');

        foreach ([['Solar cable 6mm', 20000, 1], ['Gel battery 100Ah', 120000, 2], ['Hybrid inverter 5kW', 300000, 3]] as [$item, $cost, $levels]) {
            $id = $this->create($amina, $item, 1, $cost);
            $this->actingAs($amina)->postJson("/api/requests/$id/submit")->assertOk()->assertJsonPath('data.status', 'in_approval');
            $this->assertSame($levels, Approval::where('purchase_request_id', $id)->count());
        }

        $first = Approval::where('level', 1)->first();
        $this->assertSame('manager.kasarani', $first->approver->username);
        $this->assertDatabaseHas('notifications', ['user_id' => $first->approver_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REQUEST_SUBMITTED']);
    }

    public function test_over_budget_request_also_goes_to_finance(): void
    {
        $amina = $this->user('amina');
        $item = Item::where('name', 'Gel battery 100Ah')->first();
        Budget::where('branch_id', $amina->branch_id)->where('category_id', $item->category_id)->update(['amount' => 10000]);

        $id = $this->create($amina, 'Gel battery 100Ah', 1, 20000);
        $this->actingAs($amina)->postJson("/api/requests/$id/submit")->assertOk()->assertJsonPath('data.budget_exception', true);

        $approvals = Approval::where('purchase_request_id', $id)->orderBy('level')->get();
        $this->assertCount(2, $approvals);
        $this->assertSame('Finance Officer', $approvals[1]->approver->role->name);
    }

    public function test_duplicate_item_needs_a_justification(): void
    {
        $john = $this->user('john');
        $amina = $this->user('amina');

        $first = $this->create($john, 'Lithium battery 200Ah', 2, 50000);
        $this->actingAs($john)->postJson("/api/requests/$first/submit")->assertOk();

        $second = $this->create($amina, 'Lithium battery 200Ah', 1, 50000);
        $this->actingAs($amina)->postJson("/api/requests/$second/submit")
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_JUSTIFICATION_REQUIRED')
            ->assertJsonPath('duplicates.0.branch', 'Karen');

        $this->actingAs($amina)->postJson("/api/requests/$second/submit", ['duplicate_justification' => 'Different customer project'])
            ->assertOk()->assertJsonPath('data.duplicate_flag', true);
    }

    public function test_users_only_see_the_requests_they_are_allowed_to_see(): void
    {
        $id = $this->create($this->user('amina'), 'Solar cable 6mm', 10, 2000);

        // A draft is private to its owner
        foreach (['manager.kasarani', 'finance', 'john'] as $name) {
            $this->actingAs($this->user($name))->getJson("/api/requests/$id")->assertNotFound();
        }

        $this->actingAs($this->user('amina'))->postJson("/api/requests/$id/submit")->assertOk();

        foreach (['amina', 'manager.kasarani', 'finance', 'procurement', 'headoffice'] as $name) {
            $this->actingAs($this->user($name))->getJson("/api/requests/$id")->assertOk();
        }
        foreach (['john', 'manager.karen', 'admin'] as $name) {
            $this->actingAs($this->user($name))->getJson("/api/requests/$id")->assertNotFound();
        }
    }

    public function test_a_submitted_request_cannot_be_edited(): void
    {
        $amina = $this->user('amina');
        $id = $this->create($amina, 'Solar cable 6mm', 10, 2000);
        $this->actingAs($amina)->postJson("/api/requests/$id/submit")->assertOk();

        $this->actingAs($amina)->putJson("/api/requests/$id", $this->payload('Solar cable 6mm', 5, 2000))->assertStatus(409);
    }

    public function test_only_requesters_can_raise_requests(): void
    {
        $this->actingAs($this->user('finance'))->postJson('/api/requests', $this->payload('Solar cable 6mm', 1, 1000))->assertForbidden();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->actingAs($this->user('amina'))->postJson('/api/requests', $this->payload('Solar cable 6mm', 0, 1000))
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);
    }
}