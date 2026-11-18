<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\Workflow;
use Tests\TestCase;

class BranchIsolationTest extends TestCase
{
    use RefreshDatabase, Workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A Kasarani request that is approved and ordered. Returns [request id, order id]. */
    private function kasarani(): array
    {
        $id = $this->raise('amina', 'Solar cable 6mm', 10, 2000);
        $this->decide('manager.kasarani', $id)->assertOk();
        $this->quote($id, 'P000000001A', 1900)->assertCreated();
        $this->selectQuote($id, 'P000000001A')->assertOk();
        $po = $this->issueOrder($id)->assertCreated()->json('data.id');

        return [$id, $po];
    }

    public function test_staff_of_another_branch_cannot_reach_kasarani_records(): void
    {
        [$id, $po] = $this->kasarani();

        foreach (['john', 'store.karen', 'manager.karen'] as $name) {
            $user = $this->who($name);
            $this->actingAs($user)->getJson("/api/requests/$id")->assertNotFound();
            $this->actingAs($user)->getJson("/api/requests/$id/checks")->assertNotFound();
            $this->actingAs($user)->getJson("/api/orders/$po")->assertNotFound();
        }

        foreach (['john', 'store.karen'] as $name) {
            $this->actingAs($this->who($name))->postJson("/api/requests/$id/cancel")->assertNotFound();
            $this->actingAs($this->who($name))->putJson("/api/requests/$id", [])->assertNotFound();
            $this->receiveGoods($name, $po, 1)->assertNotFound();
        }

        $this->actingAs($this->who('manager.karen'))->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_draft_is_visible_only_to_its_owner(): void
    {
        $item = Item::where('name', 'Solar cable 6mm')->first();
        $id = $this->actingAs($this->who('amina'))->postJson('/api/requests', [
            'category_id' => $item->category_id,
            'justification' => 'Needed',
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost_est' => 2000]],
        ])->assertCreated()->json('data.id');

        foreach (['manager.kasarani', 'finance', 'procurement', 'headoffice', 'store.kasarani', 'john'] as $name) {
            $this->actingAs($this->who($name))->getJson("/api/requests/$id")->assertNotFound();
        }
        $this->actingAs($this->who('amina'))->getJson("/api/requests/$id")->assertOk();
    }
}