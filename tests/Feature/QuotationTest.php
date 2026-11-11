<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\RequestLine;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'P000000001A';
    private const B = 'P000000002B';
    private const C = 'P000000003C';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->first();
    }

    private function vendor(string $pin): int
    {
        return Vendor::where('tax_pin', $pin)->value('id');
    }

    /** Raise a request as Amina and submit it. */
    private function submitted(string $item, float $qty, float $cost): int
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

    /** Raise, submit and fully approve a request. */
    private function approved(string $item, float $qty, float $cost): int
    {
        $id = $this->submitted($item, $qty, $cost);
        $this->actingAs($this->user('manager.kasarani'))->postJson("/api/requests/$id/decision", ['decision' => 'approve'])->assertOk();
        if (PurchaseRequest::find($id)->status === 'in_approval') {
            $this->actingAs($this->user('procurement'))->postJson("/api/requests/$id/decision", ['decision' => 'approve'])->assertOk();
        }
        $this->assertSame('approved', PurchaseRequest::find($id)->status);

        return $id;
    }

    private function quote(int $requestId, string $pin, float $price, int $days = 5, ?string $as = 'procurement')
    {
        $lines = RequestLine::where('purchase_request_id', $requestId)->get();

        return $this->actingAs($this->user($as))->postJson("/api/requests/$requestId/quotations", [
            'vendor_id' => $this->vendor($pin),
            'delivery_days' => $days,
            'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => $lines->map(fn ($l) => ['request_line_id' => $l->id, 'unit_price' => $price])->all(),
        ]);
    }

    private function qid(int $requestId, string $pin): int
    {
        return \App\Models\Quotation::where('purchase_request_id', $requestId)->where('vendor_id', $this->vendor($pin))->value('id');
    }

    private function select(int $requestId, int $quotationId, ?string $reason = null)
    {
        return $this->actingAs($this->user('procurement'))->postJson("/api/requests/$requestId/quotations/$quotationId/select", ['reason' => $reason]);
    }

    public function test_quotations_are_recorded_and_the_lowest_is_found(): void
    {
        $id = $this->approved('Gel battery 100Ah', 2, 60000);

        $this->quote($id, self::A, 58000)->assertCreated();
        $this->quote($id, self::B, 55000)->assertCreated()
            ->assertJsonPath('lowest_total', 110000)
            ->assertJsonCount(2, 'quotations')
            ->assertJsonPath('quotations.1.is_lowest', true)
            ->assertJsonPath('quotations.0.is_lowest', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'QUOTATION_ADDED']);
    }

    public function test_quotations_need_an_approved_request(): void
    {
        $id = $this->submitted('Solar cable 6mm', 1, 20000); // still in approval

        $this->quote($id, self::A, 1000)->assertStatus(409);
    }

    public function test_blocked_and_repeated_vendors_are_refused(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);
        Vendor::where('tax_pin', self::B)->update(['status' => 'blocked']);

        $this->quote($id, self::B, 1000)->assertStatus(422)->assertJsonValidationErrors(['vendor_id']);
        $this->quote($id, self::A, 1000)->assertCreated();
        $this->quote($id, self::A, 900)->assertStatus(422)->assertJsonValidationErrors(['vendor_id']);
    }

    public function test_every_item_needs_a_price(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);

        $this->actingAs($this->user('procurement'))->postJson("/api/requests/$id/quotations", [
            'vendor_id' => $this->vendor(self::A),
            'lines' => [['request_line_id' => 999999, 'unit_price' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    }

    public function test_a_large_request_needs_three_quotations_before_selection(): void
    {
        $id = $this->approved('Gel battery 100Ah', 2, 60000); // KES 120,000, above the threshold

        $this->quote($id, self::A, 55000);
        $this->quote($id, self::B, 56000);
        $this->select($id, $this->qid($id, self::A))->assertStatus(422)->assertJsonValidationErrors(['quotation']);

        $this->quote($id, self::C, 57000);
        $this->select($id, $this->qid($id, self::A))->assertOk()->assertJsonPath('quotations.0.is_selected', true);
        $this->assertSame('approved', PurchaseRequest::find($id)->status);
    }

    public function test_choosing_a_higher_quotation_needs_a_reason(): void
    {
        $id = $this->approved('Gel battery 100Ah', 2, 60000);
        $this->quote($id, self::A, 60000);
        $this->quote($id, self::B, 55000); // lowest
        $this->quote($id, self::C, 58000);

        $this->select($id, $this->qid($id, self::A))->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->select($id, $this->qid($id, self::A), 'Only SunTech can deliver this week')->assertOk();

        $log = \App\Models\AuditLog::where('action', 'QUOTATION_SELECTED')->latest('id')->first();
        $this->assertSame('Only SunTech can deliver this week', $log->new_values['reason']);

        // Choosing the lowest needs no reason, and replaces the earlier choice
        $this->select($id, $this->qid($id, self::B))->assertOk();
        $this->assertSame(1, \App\Models\Quotation::where('purchase_request_id', $id)->where('is_selected', true)->count());
        $this->assertTrue((bool) \App\Models\Quotation::find($this->qid($id, self::B))->is_selected);
    }

    public function test_a_small_request_needs_only_one_quotation(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);
        $this->quote($id, self::A, 19000);

        $this->select($id, $this->qid($id, self::A))->assertOk();
    }

    public function test_roles_and_permissions(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);

        $this->actingAs($this->user('finance'))->getJson("/api/requests/$id/quotations")->assertOk();
        $this->quote($id, self::A, 1000, 5, 'finance')->assertForbidden();
        $this->actingAs($this->user('amina'))->getJson("/api/requests/$id/quotations")->assertForbidden();
        $this->actingAs($this->user('manager.kasarani'))->getJson('/api/quotation-queue')->assertForbidden();
    }

    public function test_only_unselected_quotations_can_be_removed(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);
        $this->quote($id, self::A, 19000);
        $this->quote($id, self::B, 19500);
        $this->select($id, $this->qid($id, self::A))->assertOk();

        $proc = $this->user('procurement');
        $this->actingAs($proc)->deleteJson("/api/requests/$id/quotations/" . $this->qid($id, self::A))->assertStatus(409);
        $this->actingAs($proc)->deleteJson("/api/requests/$id/quotations/" . $this->qid($id, self::B))->assertOk()->assertJsonCount(1, 'quotations');
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUOTATION_REMOVED']);
    }

        public function test_earlier_prices_for_the_same_item_are_shown(): void
    {
        $first = $this->approved('Solar cable 6mm', 1, 20000);
        $this->quote($first, self::A, 1900)->assertCreated();

        $second = $this->approved('Solar cable 6mm', 1, 20000);
        $response = $this->actingAs($this->user('procurement'))->getJson("/api/requests/$second/quotations");

        $response->assertOk();
        $history = $response->json('lines.0.history');
        $this->assertNotEmpty($history, 'No earlier prices were returned for the same item.');
        $this->assertSame('SunTech Distributors Ltd', $history[0]['vendor']);
        $this->assertEquals(1900, $history[0]['unit_price']);
        $this->assertSame('quoted', $history[0]['kind']);
    }
    public function test_the_queue_lists_approved_requests(): void
    {
        $id = $this->approved('Solar cable 6mm', 1, 20000);
        $this->submitted('Gel battery 100Ah', 1, 10000); // not approved, must not appear
        $this->quote($id, self::A, 19000);

        $this->actingAs($this->user('procurement'))->getJson('/api/quotation-queue')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quotations_count', 1)
            ->assertJsonPath('data.0.selected', false);
    }
}