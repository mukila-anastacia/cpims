<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\GoodsReceipt;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Item;
use App\Models\RequestLine;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'P000000001A';
    private const B = 'P000000002B';

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

    private function approved(string $item, float $qty, float $cost): int
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
        $this->actingAs($this->user('manager.kasarani'))->postJson("/api/requests/$id/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', PurchaseRequest::find($id)->status);

        return $id;
    }

    private function quote(int $requestId, string $pin, float $price)
    {
        $lines = RequestLine::where('purchase_request_id', $requestId)->get();

        return $this->actingAs($this->user('procurement'))->postJson("/api/requests/$requestId/quotations", [
            'vendor_id' => $this->vendor($pin),
            'delivery_days' => 5,
            'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => $lines->map(fn ($l) => ['request_line_id' => $l->id, 'unit_price' => $price])->all(),
        ]);
    }

    /** Approved request (KES 20,000, 10 metres) with a quotation from vendor A selected. */
    private function ready(float $price = 1900): int
    {
        $id = $this->approved('Solar cable 6mm', 10, 2000);
        $this->quote($id, self::A, $price)->assertCreated();
        $qid = Quotation::where('purchase_request_id', $id)->where('vendor_id', $this->vendor(self::A))->value('id');
        $this->actingAs($this->user('procurement'))->postJson("/api/requests/$id/quotations/$qid/select")->assertOk();

        return $id;
    }

    private function order(int $requestId, string $as = 'procurement')
    {
        return $this->actingAs($this->user($as))->postJson("/api/requests/$requestId/order");
    }

    private function receive(string $as, int $orderId, float $qty, array $extra = [])
    {
        $lineId = PoLine::where('purchase_order_id', $orderId)->value('id');

        return $this->actingAs($this->user($as))->postJson("/api/orders/$orderId/receipts", array_merge([
            'delivery_note_no' => 'DN-100',
            'lines' => [['po_line_id' => $lineId, 'qty_received' => $qty, 'item_condition' => 'good']],
        ], $extra));
    }

    public function test_purchase_order_is_issued_from_the_selected_quotation(): void
    {
        $id = $this->ready();

        $res = $this->order($id)->assertCreated()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.total', 19000)
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.vendor.name', 'SunTech Distributors Ltd');

        $this->assertMatchesRegularExpression('/^PO-\d{4}$/', $res->json('data.po_no'));
        $this->assertSame('ordered', PurchaseRequest::find($id)->status);
        $this->assertTrue(AppNotification::where('user_id', $this->user('amina')->id)->where('message', 'like', '%ordered%')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'PO_ISSUED']);
    }

    public function test_an_order_needs_a_selected_quotation(): void
    {
        $id = $this->approved('Solar cable 6mm', 10, 2000);
        $this->quote($id, self::A, 1900)->assertCreated(); // recorded but not selected

        $this->order($id)->assertStatus(422)->assertJsonValidationErrors(['quotation']);
    }

    public function test_a_request_can_only_be_ordered_once(): void
    {
        $id = $this->ready();

        $this->order($id)->assertCreated();
        $this->order($id)->assertStatus(409);
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_a_blocked_vendor_cannot_be_ordered(): void
    {
        $id = $this->ready();
        Vendor::where('tax_pin', self::A)->update(['status' => 'blocked']);

        $this->order($id)->assertStatus(422)->assertJsonValidationErrors(['quotation']);
    }

    public function test_only_procurement_can_issue_orders(): void
    {
        $id = $this->ready();

        $this->order($id, 'finance')->assertForbidden();
        $this->order($id, 'amina')->assertForbidden();
    }

    public function test_goods_can_be_received_in_parts(): void
    {
        $id = $this->ready();
        $po = $this->order($id)->json('data.id');

        $first = $this->receive('store.kasarani', $po, 4)->assertCreated()
            ->assertJsonPath('data.status', 'partly_received')
            ->assertJsonPath('data.lines.0.received', 4)
            ->assertJsonPath('data.lines.0.remaining', 6);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}$/', $first->json('data.receipts.0.receipt_no'));
        $this->assertSame('ordered', PurchaseRequest::find($id)->status);

        $this->receive('store.kasarani', $po, 6)->assertCreated()->assertJsonPath('data.status', 'received');
        $this->assertSame('received', PurchaseRequest::find($id)->status);
        $this->assertTrue(AppNotification::where('user_id', $this->user('finance')->id)->where('message', 'like', '%received in full%')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'GOODS_RECEIVED']);

        $this->receive('store.kasarani', $po, 1)->assertStatus(409);
    }

    public function test_the_requester_cannot_receive_their_own_order(): void
    {
        $po = $this->order($this->ready())->json('data.id');

        $this->receive('amina', $po, 4)->assertForbidden();
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACCESS_DENIED', 'user_id' => $this->user('amina')->id]);

        $this->actingAs($this->user('amina'))->getJson("/api/orders/$po")->assertOk()
            ->assertJsonPath('data.rights.can_receive', false);
    }

    public function test_receiving_more_than_ordered_is_refused(): void
    {
        $po = $this->order($this->ready())->json('data.id');

        $this->receive('store.kasarani', $po, 11)->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $this->receive('store.kasarani', $po, 0)->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_staff_from_another_branch_cannot_see_or_receive(): void
    {
        $po = $this->order($this->ready())->json('data.id');

        $this->actingAs($this->user('store.karen'))->getJson("/api/orders/$po")->assertNotFound();
        $this->receive('store.karen', $po, 4)->assertNotFound();
    }

    public function test_procurement_can_record_a_delivery_with_a_logged_override(): void
    {
        $po = $this->order($this->ready())->json('data.id');
        $proc = $this->user('procurement');

        $this->receive('procurement', $po, 4)->assertStatus(422)->assertJsonValidationErrors(['override_reason']);

        $this->receive('procurement', $po, 4, ['override_reason' => 'Branch store was closed on delivery day'])->assertCreated();
        $this->assertDatabaseHas('goods_receipts', ['purchase_order_id' => $po, 'override_by' => $proc->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GOODS_RECEIVED_OVERRIDE']);
    }

    public function test_who_can_see_which_orders(): void
    {
        $this->order($this->ready())->assertCreated();

        $this->actingAs($this->user('finance'))->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user('store.kasarani'))->getJson('/api/orders?status=awaiting')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user('store.karen'))->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user('manager.karen'))->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user('admin'))->getJson('/api/orders')->assertForbidden();
    }

    public function test_quotations_are_locked_once_the_order_is_issued(): void
    {
        $id = $this->ready();
        $this->order($id)->assertCreated();

        $this->quote($id, self::B, 1800)->assertStatus(409);
    }
}