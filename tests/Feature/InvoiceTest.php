<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Item;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\RequestLine;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'P000000001A';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->first();
    }

    private function approved(float $qty): int
    {
        $amina = $this->user('amina');
        $it = Item::where('name', 'Solar cable 6mm')->first();
        $id = $this->actingAs($amina)->postJson('/api/requests', [
            'category_id' => $it->category_id,
            'needed_by' => now()->addDays(7)->toDateString(),
            'justification' => 'Needed for a customer installation',
            'lines' => [['item_id' => $it->id, 'quantity' => $qty, 'unit_cost_est' => 2000]],
        ])->json('data.id');
        $this->actingAs($amina)->postJson("/api/requests/$id/submit", ['duplicate_justification' => 'Needed again for another job'])->assertOk();
        $this->actingAs($this->user('manager.kasarani'))->postJson("/api/requests/$id/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', PurchaseRequest::find($id)->status);

        return $id;
    }

    /** An order from vendor A at the given price; returns the purchase order id. */
    private function order(float $price = 1900, float $qty = 10): int
    {
        $id = $this->approved($qty);
        $proc = $this->user('procurement');
        $lines = RequestLine::where('purchase_request_id', $id)->get();

        $this->actingAs($proc)->postJson("/api/requests/$id/quotations", [
            'vendor_id' => Vendor::where('tax_pin', self::A)->value('id'),
            'delivery_days' => 5,
            'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => $lines->map(fn ($l) => ['request_line_id' => $l->id, 'unit_price' => $price])->all(),
        ])->assertCreated();

        $qid = Quotation::where('purchase_request_id', $id)->value('id');
        $this->actingAs($proc)->postJson("/api/requests/$id/quotations/$qid/select")->assertOk();

        return $this->actingAs($proc)->postJson("/api/requests/$id/order")->assertCreated()->json('data.id');
    }

    private function receive(int $po, float $qty): void
    {
        $lineId = PoLine::where('purchase_order_id', $po)->value('id');
        $this->actingAs($this->user('store.kasarani'))->postJson("/api/orders/$po/receipts", [
            'delivery_note_no' => 'DN-1',
            'lines' => [['po_line_id' => $lineId, 'qty_received' => $qty, 'item_condition' => 'good']],
        ])->assertCreated();
    }

    private function invoice(string $as, int $po, string $no, float $qty, float $price)
    {
        $lineId = PoLine::where('purchase_order_id', $po)->value('id');

        return $this->actingAs($this->user($as))->postJson('/api/invoices', [
            'purchase_order_id' => $po,
            'invoice_no' => $no,
            'invoice_date' => now()->toDateString(),
            'lines' => [['po_line_id' => $lineId, 'quantity' => $qty, 'unit_price' => $price]],
        ]);
    }

    public function test_a_matching_invoice_is_approved_and_closes_the_order(): void
    {
        $po = $this->order();
        $this->receive($po, 10);

        $this->invoice('finance', $po, 'INV-001', 10, 1900)->assertCreated()
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.total', 19000)
            ->assertJsonPath('data.lines.0.ok', true);

        $order = PurchaseOrder::find($po);
        $this->assertSame('closed', $order->status);
        $this->assertSame('closed', $order->purchaseRequest->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'INVOICE_MATCHED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ORDER_CLOSED']);
    }

    public function test_price_differences_beyond_the_tolerance_are_exceptions(): void
    {
        $inside = $this->order();
        $this->receive($inside, 10);
        $this->invoice('finance', $inside, 'INV-001', 10, 1930)->assertCreated()->assertJsonPath('data.match_status', 'matched'); // 1.6% above

        $outside = $this->order();
        $this->receive($outside, 10);
        $this->invoice('finance', $outside, 'INV-002', 10, 1950)->assertCreated()
            ->assertJsonPath('data.match_status', 'exception')
            ->assertJsonPath('data.lines.0.ok', false);

        $this->assertSame('received', PurchaseOrder::find($outside)->status); // stays open
        $this->assertDatabaseHas('audit_logs', ['action' => 'INVOICE_EXCEPTION']);
        $this->assertTrue(AppNotification::where('user_id', $this->user('procurement')->id)->where('message', 'like', '%exception%')->exists());
    }

    public function test_invoicing_more_than_was_received_is_an_exception(): void
    {
        $po = $this->order();
        $this->receive($po, 6);

        $this->invoice('finance', $po, 'INV-001', 10, 1900)->assertCreated()
            ->assertJsonPath('data.match_status', 'exception')
            ->assertJsonPath('data.lines.0.expected', 6);
    }

    public function test_a_repeated_invoice_number_from_the_same_vendor_is_refused(): void
    {
        $first = $this->order();
        $this->receive($first, 10);
        $this->invoice('finance', $first, 'INV-001', 10, 1900)->assertCreated();

        $second = $this->order();
        $this->receive($second, 10);
        $this->invoice('finance', $second, 'INV-001', 10, 1900)->assertStatus(422)->assertJsonValidationErrors(['invoice_no']);
        $this->invoice('finance', $second, 'INV-002', 10, 1900)->assertCreated();
    }

    public function test_an_order_with_no_deliveries_cannot_be_invoiced(): void
    {
        $po = $this->order();

        $this->invoice('finance', $po, 'INV-001', 10, 1900)->assertStatus(422)->assertJsonValidationErrors(['purchase_order_id']);
    }

    public function test_who_can_enter_and_view_invoices(): void
    {
        $po = $this->order();
        $this->receive($po, 10);

        $this->invoice('procurement', $po, 'INV-001', 10, 1900)->assertForbidden();
        $this->invoice('amina', $po, 'INV-001', 10, 1900)->assertForbidden();
        $this->invoice('finance', $po, 'INV-001', 10, 1900)->assertCreated();

        foreach (['finance', 'procurement', 'headoffice'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data');
        }
        foreach (['amina', 'manager.kasarani', 'admin'] as $name) {
            $this->actingAs($this->user($name))->getJson('/api/invoices')->assertForbidden();
        }
    }

    public function test_an_exception_can_be_resolved_by_procurement(): void
    {
        $po = $this->order();
        $this->receive($po, 10);
        $id = $this->invoice('finance', $po, 'INV-001', 10, 1990)->assertJsonPath('data.match_status', 'exception')->json('data.id');

        $this->actingAs($this->user('finance'))->postJson("/api/invoices/$id/resolve", ['comment' => 'ok'])->assertForbidden();
        $proc = $this->user('procurement');
        $this->actingAs($proc)->postJson("/api/invoices/$id/resolve", [])->assertStatus(422)->assertJsonValidationErrors(['comment']);

        $this->actingAs($proc)->postJson("/api/invoices/$id/resolve", ['comment' => 'Vendor confirmed a fuel surcharge'])
            ->assertOk()->assertJsonPath('data.match_status', 'resolved');

        $this->assertSame('closed', PurchaseOrder::find($po)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'INVOICE_RESOLVED']);
        $this->assertTrue(AppNotification::where('user_id', $this->user('finance')->id)->where('message', 'like', '%resolved%')->exists());

        $this->actingAs($proc)->postJson("/api/invoices/$id/resolve", ['comment' => 'again'])->assertStatus(409);
    }

    public function test_partial_deliveries_can_be_invoiced_in_parts(): void
    {
        $po = $this->order();

        $this->receive($po, 4);
        $this->invoice('finance', $po, 'INV-001', 4, 1900)->assertCreated()->assertJsonPath('data.match_status', 'matched');
        $this->assertSame('partly_received', PurchaseOrder::find($po)->status);

        $this->receive($po, 6);
        $this->invoice('finance', $po, 'INV-002', 6, 1900)->assertCreated()->assertJsonPath('data.match_status', 'matched');
        $this->assertSame('closed', PurchaseOrder::find($po)->status);
    }

    public function test_invoiceable_orders_are_listed_for_finance(): void
    {
        $po = $this->order();
        $finance = $this->user('finance');

        $this->actingAs($finance)->getJson('/api/invoice-orders')->assertOk()->assertJsonCount(0, 'data');

        $this->receive($po, 4);
        $this->actingAs($finance)->getJson('/api/invoice-orders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lines.0.to_invoice', 4);

        $this->actingAs($this->user('procurement'))->getJson('/api/invoice-orders')->assertForbidden();
    }
}