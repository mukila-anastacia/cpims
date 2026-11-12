<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Approval;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /** Turn the selected quotation into a purchase order (POR-01). */
    public function create(User $user, PurchaseRequest $request): PurchaseOrder
    {
        if ($request->status !== 'approved') {
            abort(409, 'Only approved requests can be ordered, and only once.');
        }

        $request->load(['lines', 'quotations.lines', 'quotations.vendor', 'requester']);
        $quote = $request->quotations->firstWhere('is_selected', true);

        if (! $quote) {
            throw ValidationException::withMessages(['quotation' => 'Select a quotation before issuing the purchase order.']);
        }
        if ($quote->vendor->status !== 'active') {
            throw ValidationException::withMessages(['quotation' => 'The selected vendor is blocked. Select another quotation.']);
        }

        return DB::transaction(function () use ($user, $request, $quote) {
            $po = PurchaseOrder::create([
                'po_no' => 'TMP-' . uniqid(),
                'purchase_request_id' => $request->id,
                'quotation_id' => $quote->id,
                'vendor_id' => $quote->vendor_id,
                'issued_by' => $user->id,
                'status' => 'issued',
                'total' => 0,
                'expected_delivery' => $quote->delivery_days !== null ? now()->addDays($quote->delivery_days)->toDateString() : null,
                'issued_at' => now(),
            ]);
            $po->update(['po_no' => sprintf('PO-%04d', $po->id)]);

            $total = 0;
            foreach ($request->lines as $line) {
                $price = (float) ($quote->lines->firstWhere('request_line_id', $line->id)?->unit_price ?? 0);
                $po->lines()->create([
                    'request_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $price,
                ]);
                $total += (float) $line->quantity * $price;
            }
            $po->update(['total' => round($total, 2)]);
            $request->update(['status' => 'ordered']);

            $this->notify($request->requester_id, "Your request {$request->request_no} was ordered from {$quote->vendor->name} ({$po->po_no}).", $po->id);

            AuditService::log('PO_ISSUED', 'purchase_order', $po->id, ['request_status' => 'approved'], [
                'po_no' => $po->po_no, 'request' => $request->request_no, 'vendor' => $quote->vendor->name, 'total' => round($total, 2),
            ]);

            return $po;
        });
    }

    /** Who may record a delivery, and whether it counts as an override (POR-03, BR-02). */
    public function receiveRights(User $user, PurchaseOrder $po): array
    {
        $po->loadMissing('purchaseRequest');
        $req = $po->purchaseRequest;

        if (! in_array($po->status, ['issued', 'partly_received'], true)) {
            return ['allowed' => false, 'override' => false, 'note' => null];
        }
        if ($user->hasRole('Procurement Administrator')) {
            return ['allowed' => true, 'override' => true, 'note' => null];
        }
        if ($user->hasRole('Requester')) {
            if ($user->branch_id !== $req->branch_id) {
                return ['allowed' => false, 'override' => false, 'note' => 'Only staff at the ordering branch can record this delivery.'];
            }
            $approved = Approval::where('purchase_request_id', $req->id)->where('approver_id', $user->id)->exists();
            if ($req->requester_id === $user->id || $approved) {
                return ['allowed' => false, 'override' => false, 'note' => 'You requested or approved this purchase, so a colleague must record its receipt.'];
            }

            return ['allowed' => true, 'override' => false, 'note' => null];
        }

        return ['allowed' => false, 'override' => false, 'note' => null];
    }

    /** Record a full or partial delivery (POR-02). */
    public function receive(User $user, PurchaseOrder $po, array $data): void
    {
        $po->loadMissing('purchaseRequest');

        if (! in_array($po->status, ['issued', 'partly_received'], true)) {
            abort(409, 'This order is not waiting for goods.');
        }
        $rights = $this->receiveRights($user, $po);
        if (! $rights['allowed']) {
            AuditService::log('ACCESS_DENIED', 'purchase_order', $po->id, null, ['reason' => $rights['note'] ?? 'not allowed to receive']);
            abort(403, $rights['note'] ?? 'You cannot record a delivery for this order.');
        }

        $reason = trim((string) ($data['override_reason'] ?? ''));
        if ($rights['override'] && $reason === '') {
            throw ValidationException::withMessages(['override_reason' => 'Explain why you are recording this delivery on behalf of the branch.']);
        }

        DB::transaction(function () use ($user, $po, $data, $rights, $reason) {
            $po = PurchaseOrder::with(['lines.item', 'purchaseRequest.requester'])->lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, ['issued', 'partly_received'], true)) {
                abort(409, 'This order is not waiting for goods.');
            }

            $done = DB::table('receipt_lines')
                ->join('goods_receipts as g', 'g.id', '=', 'receipt_lines.goods_receipt_id')
                ->where('g.purchase_order_id', $po->id)
                ->groupBy('receipt_lines.po_line_id')
                ->selectRaw('receipt_lines.po_line_id, SUM(receipt_lines.qty_received) as qty')
                ->pluck('qty', 'po_line_id');

            $given = collect($data['lines'])->keyBy('po_line_id');
            if ($given->keys()->diff($po->lines->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Some lines do not belong to this order.']);
            }

            $rows = [];
            foreach ($given as $lineId => $row) {
                $qty = (float) $row['qty_received'];
                if ($qty <= 0) {
                    continue;
                }
                $line = $po->lines->firstWhere('id', $lineId);
                $remaining = (float) $line->quantity - (float) ($done[$lineId] ?? 0);
                if ($qty > $remaining + 0.0001) {
                    throw ValidationException::withMessages(['lines' =>
                        "{$line->item->name}: only " . rtrim(rtrim(number_format($remaining, 2, '.', ''), '0'), '.') . ' still expected, not more.']);
                }
                $rows[] = ['po_line_id' => $lineId, 'qty_received' => $qty, 'item_condition' => $row['item_condition']];
            }
            if (! $rows) {
                throw ValidationException::withMessages(['lines' => 'Enter a quantity for at least one item.']);
            }

            $receipt = GoodsReceipt::create([
                'receipt_no' => 'TMP-' . uniqid(),
                'purchase_order_id' => $po->id,
                'received_by' => $user->id,
                'received_at' => now(),
                'delivery_note_no' => $data['delivery_note_no'] ?? null,
                'override_by' => $rights['override'] ? $user->id : null,
                'override_reason' => $rights['override'] ? $reason : null,
            ]);
            $receipt->update(['receipt_no' => sprintf('GRN-%04d', $receipt->id)]);
            foreach ($rows as $r) {
                $receipt->lines()->create($r);
            }

            $complete = $po->lines->every(function ($l) use ($done, $rows) {
                $got = (float) ($done[$l->id] ?? 0) + collect($rows)->where('po_line_id', $l->id)->sum('qty_received');

                return $got + 0.0001 >= (float) $l->quantity;
            });

            $po->update(['status' => $complete ? 'received' : 'partly_received']);
            $req = $po->purchaseRequest;
            if ($complete) {
                $req->update(['status' => 'received']);
            }

            $this->notify($req->requester_id, "Goods for {$req->request_no} ({$po->po_no}) were received " . ($complete ? 'in full.' : 'in part.'), $po->id);
            if ($complete) {
                $financeId = Role::where('name', 'Finance Officer')->value('id');
                foreach (User::where('role_id', $financeId)->where('status', 'active')->pluck('id') as $uid) {
                    $this->notify($uid, "Order {$po->po_no} was received in full and is ready for invoice matching.", $po->id);
                }
            }

            AuditService::log($rights['override'] ? 'GOODS_RECEIVED_OVERRIDE' : 'GOODS_RECEIVED', 'purchase_order', $po->id, null, [
                'order' => $po->po_no, 'receipt' => $receipt->receipt_no, 'complete' => $complete,
                'lines' => count($rows), 'override_reason' => $rights['override'] ? $reason : null,
            ]);
        });
    }

    private function notify(int $userId, string $message, int $orderId): void
    {
        AppNotification::create(['user_id' => $userId, 'message' => $message, 'link' => "/orders/{$orderId}"]);
    }
}