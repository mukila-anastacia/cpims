<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\Workflow;
use Tests\TestCase;

class EndToEndTest extends TestCase
{
    use RefreshDatabase, Workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_a_purchase_goes_from_request_to_a_closed_order(): void
    {
        // Amina (Kasarani) asks for 2 gel batteries at KES 60,000 each: two approval levels and three quotations are needed
        $id = $this->raise('amina', 'Gel battery 100Ah', 2, 60000);
        $this->assertSame('in_approval', PurchaseRequest::find($id)->status);

        $this->decide('manager.kasarani', $id)->assertOk()->assertJsonPath('data.status', 'in_approval');
        $this->decide('procurement', $id)->assertOk()->assertJsonPath('data.status', 'approved');

        // One quotation is not enough, and the cheapest needs no reason but another one does
        $this->quote($id, 'P000000001A', 58000)->assertCreated();
        $this->selectQuote($id, 'P000000001A')->assertStatus(422);
        $this->quote($id, 'P000000002B', 55000)->assertCreated();
        $this->quote($id, 'P000000003C', 57000)->assertCreated();
        $this->selectQuote($id, 'P000000001A')->assertStatus(422);
        $this->selectQuote($id, 'P000000001A', 'Only SunTech can deliver this week')->assertOk();

        $po = $this->issueOrder($id)->assertCreated()->assertJsonPath('data.total', 116000)->json('data.id');
        $this->assertSame('ordered', PurchaseRequest::find($id)->status);

        // Delivery in two parts. The requester cannot receive their own order.
        $this->receiveGoods('amina', $po, 1)->assertForbidden();
        $this->receiveGoods('store.kasarani', $po, 1)->assertCreated()->assertJsonPath('data.status', 'partly_received');

        // The first part is invoiced correctly
        $this->invoiceFor($po, 'INV-5001', 1, 58000)->assertCreated()->assertJsonPath('data.match_status', 'matched');
        $this->assertSame('partly_received', PurchaseOrder::find($po)->status);

        // The second part is invoiced about 5% too high
        $this->receiveGoods('store.kasarani', $po, 1)->assertCreated()->assertJsonPath('data.status', 'received');
        $bad = $this->invoiceFor($po, 'INV-5002', 1, 61000)->assertCreated()->assertJsonPath('data.match_status', 'exception')->json('data.id');
        $this->assertSame('received', PurchaseOrder::find($po)->status);

        // Procurement resolves it and the order closes
        $this->actingAs($this->who('procurement'))->postJson("/api/invoices/$bad/resolve", ['comment' => 'Vendor confirmed a fuel surcharge'])->assertOk();
        $this->assertSame('closed', PurchaseOrder::find($po)->status);
        $this->assertSame('closed', PurchaseRequest::find($id)->status);

        $this->actingAs($this->who('headoffice'))->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('indicators.submitted', 1)
            ->assertJsonPath('indicators.approval_turnaround.count', 1)
            ->assertJsonPath('indicators.request_to_order.count', 1)
            ->assertJsonPath('by_status.closed', 1)
            ->assertJsonPath('spend_by_vendor.0.name', 'SunTech Distributors Ltd')
            ->assertJsonPath('spend_by_vendor.0.total', 116000);

        $actions = AuditLog::pluck('action')->unique()->all();
        foreach (['REQUEST_CREATED', 'REQUEST_SUBMITTED', 'REQUEST_APPROVED', 'QUOTATION_ADDED', 'QUOTATION_SELECTED', 'PO_ISSUED', 'ACCESS_DENIED', 'GOODS_RECEIVED', 'INVOICE_MATCHED', 'INVOICE_EXCEPTION', 'INVOICE_RESOLVED', 'ORDER_CLOSED'] as $expected) {
            $this->assertContains($expected, $actions);
        }
    }
}