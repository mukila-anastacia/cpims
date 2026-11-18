<?php

namespace Tests\Concerns;

use App\Models\Item;
use App\Models\PoLine;
use App\Models\Quotation;
use App\Models\RequestLine;
use App\Models\User;
use App\Models\Vendor;

trait Workflow
{
    protected function who(string $username): User
    {
        return User::where('username', $username)->first();
    }

    /** Raise and submit a request as the given requester. Returns its id. */
    protected function raise(string $username, string $itemName, float $qty, float $cost): int
    {
        $item = Item::where('name', $itemName)->first();
        $user = $this->who($username);
        $id = $this->actingAs($user)->postJson('/api/requests', [
            'category_id' => $item->category_id,
            'needed_by' => now()->addDays(7)->toDateString(),
            'justification' => 'Needed for a customer installation',
            'lines' => [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost_est' => $cost]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/requests/$id/submit", ['duplicate_justification' => 'Needed again for another job'])->assertOk();

        return $id;
    }

    protected function decide(string $username, int $id, string $decision = 'approve', ?string $comment = null)
    {
        return $this->actingAs($this->who($username))->postJson("/api/requests/$id/decision", ['decision' => $decision, 'comment' => $comment]);
    }

    protected function quote(int $requestId, string $pin, float $price)
    {
        $lines = RequestLine::where('purchase_request_id', $requestId)->get();

        return $this->actingAs($this->who('procurement'))->postJson("/api/requests/$requestId/quotations", [
            'vendor_id' => Vendor::where('tax_pin', $pin)->value('id'),
            'delivery_days' => 5,
            'valid_until' => now()->addDays(14)->toDateString(),
            'lines' => $lines->map(fn ($l) => ['request_line_id' => $l->id, 'unit_price' => $price])->all(),
        ]);
    }

    protected function selectQuote(int $requestId, string $pin, ?string $reason = null)
    {
        $qid = Quotation::where('purchase_request_id', $requestId)->where('vendor_id', Vendor::where('tax_pin', $pin)->value('id'))->value('id');

        return $this->actingAs($this->who('procurement'))->postJson("/api/requests/$requestId/quotations/$qid/select", ['reason' => $reason]);
    }

    protected function issueOrder(int $requestId)
    {
        return $this->actingAs($this->who('procurement'))->postJson("/api/requests/$requestId/order");
    }

    protected function receiveGoods(string $username, int $orderId, float $qty)
    {
        $lineId = PoLine::where('purchase_order_id', $orderId)->value('id');

        return $this->actingAs($this->who($username))->postJson("/api/orders/$orderId/receipts", [
            'delivery_note_no' => 'DN-1',
            'lines' => [['po_line_id' => $lineId, 'qty_received' => $qty, 'item_condition' => 'good']],
        ]);
    }

    protected function invoiceFor(int $orderId, string $number, float $qty, float $price)
    {
        $lineId = PoLine::where('purchase_order_id', $orderId)->value('id');

        return $this->actingAs($this->who('finance'))->postJson('/api/invoices', [
            'purchase_order_id' => $orderId,
            'invoice_no' => $number,
            'invoice_date' => now()->toDateString(),
            'lines' => [['po_line_id' => $lineId, 'quantity' => $qty, 'unit_price' => $price]],
        ]);
    }
}