<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /** Received and invoiced quantities per order line. */
    public function progress(PurchaseOrder $po): Collection
    {
        $po->loadMissing('lines.item');

        $received = DB::table('receipt_lines as r')
            ->join('goods_receipts as g', 'g.id', '=', 'r.goods_receipt_id')
            ->where('g.purchase_order_id', $po->id)
            ->groupBy('r.po_line_id')
            ->selectRaw('r.po_line_id, SUM(r.qty_received) as qty')
            ->pluck('qty', 'po_line_id');

        $invoiced = DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.purchase_order_id', $po->id)
            ->groupBy('l.po_line_id')
            ->selectRaw('l.po_line_id, SUM(l.quantity) as qty')
            ->pluck('qty', 'po_line_id');

        return $po->lines->map(function ($l) use ($received, $invoiced) {
            $got = (float) ($received[$l->id] ?? 0);
            $billed = (float) ($invoiced[$l->id] ?? 0);

            return [
                'po_line_id' => $l->id,
                'item' => $l->item->name,
                'unit' => $l->item->unit,
                'ordered' => (float) $l->quantity,
                'received' => $got,
                'invoiced' => $billed,
                'to_invoice' => max(0, $got - $billed),
                'unit_price' => (float) $l->unit_price,
            ];
        })->values();
    }

    /** Record an invoice and run the three-way match straight away (INV-01, INV-02, INV-04). */
    public function record(User $user, PurchaseOrder $po, array $data): Invoice
    {
        $po->load(['lines.item', 'vendor', 'purchaseRequest']);

        if (! in_array($po->status, ['partly_received', 'received'], true)) {
            $msg = $po->status === 'issued'
                ? 'No goods have been received for this order yet, so there is nothing to match.'
                : 'This order is already closed or cancelled.';
            throw ValidationException::withMessages(['purchase_order_id' => $msg]);
        }

        $no = trim($data['invoice_no']);
        if (Invoice::where('vendor_id', $po->vendor_id)->where('invoice_no', $no)->exists()) {
            throw ValidationException::withMessages(['invoice_no' => 'This vendor already has an invoice with this number.']);
        }

        $given = collect($data['lines']);
        $ids = $given->pluck('po_line_id');
        if ($ids->diff($po->lines->pluck('id'))->isNotEmpty() || $ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'The invoice lines do not match this order.']);
        }

        try {
            return DB::transaction(function () use ($user, $po, $data, $no, $given) {
                $total = $given->sum(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price']);

                $inv = Invoice::create([
                    'purchase_order_id' => $po->id,
                    'vendor_id' => $po->vendor_id,
                    'invoice_no' => $no,
                    'invoice_date' => $data['invoice_date'],
                    'total' => round($total, 2),
                    'entered_by' => $user->id,
                    'match_status' => 'pending',
                ]);
                foreach ($given as $l) {
                    $inv->lines()->create(['po_line_id' => $l['po_line_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']]);
                }

                $results = $this->evaluate($inv->fresh());
                $ok = $results->every(fn ($r) => $r['ok']);
                $inv->update(['match_status' => $ok ? 'matched' : 'exception']);

                if ($ok) {
                    AuditService::log('INVOICE_MATCHED', 'invoice', $inv->id, null, ['invoice' => $no, 'order' => $po->po_no, 'total' => round($total, 2)]);
                    $this->closeIfComplete($po->id);
                } else {
                    AuditService::log('INVOICE_EXCEPTION', 'invoice', $inv->id, null, [
                        'invoice' => $no, 'order' => $po->po_no, 'issues' => $results->flatMap(fn ($r) => $r['issues'])->values()->all(),
                    ]);
                    $procurement = Role::where('name', 'Procurement Administrator')->value('id');
                    foreach (User::where('role_id', $procurement)->where('status', 'active')->pluck('id') as $uid) {
                        $this->notify($uid, "Invoice {$no} for {$po->po_no} has a matching exception and needs your review.", $inv->id);
                    }
                }

                return $inv->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['invoice_no' => 'This vendor already has an invoice with this number.']);
        }
    }

    /** Compare each invoice line with the order and with what was received and not yet invoiced (BR-05). */
    public function evaluate(Invoice $inv): Collection
    {
        $inv->loadMissing('lines.poLine.item');
        $tolerance = (float) Setting::get('price_tolerance_pct', 2);

        return $inv->lines->map(function ($l) use ($inv, $tolerance) {
            $pl = $l->poLine;
            $received = (float) DB::table('receipt_lines')->where('po_line_id', $pl->id)->sum('qty_received');
            $previous = (float) DB::table('invoice_lines')
                ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                ->where('invoice_lines.po_line_id', $pl->id)->where('invoices.id', '<', $inv->id)
                ->sum('invoice_lines.quantity');
            $expected = max(0, $received - $previous);

            $orderPrice = (float) $pl->unit_price;
            $price = (float) $l->unit_price;
            $diffPct = $orderPrice > 0 ? (($price - $orderPrice) / $orderPrice) * 100 : 0;

            $qtyOk = abs((float) $l->quantity - $expected) < 0.0001;
            $priceOk = abs($diffPct) <= $tolerance + 0.0001;

            $issues = [];
            if (! $qtyOk) {
                $issues[] = 'Invoiced ' . $this->n($l->quantity) . ' but ' . $this->n($expected) . ' received and not yet invoiced.';
            }
            if (! $priceOk) {
                $issues[] = sprintf('Unit price %s is %s%% %s the order price %s (tolerance %s%%).',
                    number_format($price, 2), number_format(abs($diffPct), 1), $diffPct > 0 ? 'above' : 'below', number_format($orderPrice, 2), $this->n($tolerance));
            }

            return [
                'item' => $pl->item->name,
                'unit' => $pl->item->unit,
                'quantity' => (float) $l->quantity,
                'unit_price' => $price,
                'line_total' => round((float) $l->quantity * $price, 2),
                'order_price' => $orderPrice,
                'ordered' => (float) $pl->quantity,
                'received' => $received,
                'expected' => $expected,
                'ok' => $qtyOk && $priceOk,
                'issues' => $issues,
            ];
        })->values();
    }

    /** Procurement accepts an exception with a written explanation. */
    public function resolve(User $user, Invoice $inv, ?string $comment): Invoice
    {
        if ($inv->match_status !== 'exception') {
            abort(409, 'Only invoices with an exception can be resolved.');
        }
        $comment = trim((string) $comment);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Explain how the exception was resolved.']);
        }

        return DB::transaction(function () use ($user, $inv, $comment) {
            $inv->update(['match_status' => 'resolved', 'resolution_comment' => $comment, 'resolved_by' => $user->id]);
            AuditService::log('INVOICE_RESOLVED', 'invoice', $inv->id, ['match_status' => 'exception'], ['match_status' => 'resolved', 'comment' => $comment]);
            $this->notify($inv->entered_by, "Invoice {$inv->invoice_no} was resolved by Procurement: {$comment}", $inv->id);
            $this->closeIfComplete($inv->purchase_order_id);

            return $inv->fresh();
        });
    }

    /** An order closes when it is fully received, fully invoiced and has no open exceptions. */
    private function closeIfComplete(int $poId): void
    {
        $po = PurchaseOrder::with(['lines', 'purchaseRequest'])->lockForUpdate()->find($poId);
        if (! $po || $po->status !== 'received') {
            return;
        }
        if (Invoice::where('purchase_order_id', $po->id)->whereIn('match_status', ['pending', 'exception'])->exists()) {
            return;
        }

        $invoiced = DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.purchase_order_id', $po->id)
            ->groupBy('l.po_line_id')
            ->selectRaw('l.po_line_id, SUM(l.quantity) as qty')
            ->pluck('qty', 'po_line_id');

        foreach ($po->lines as $line) {
            if ((float) ($invoiced[$line->id] ?? 0) + 0.0001 < (float) $line->quantity) {
                return;
            }
        }

        $po->update(['status' => 'closed']);
        $po->purchaseRequest->update(['status' => 'closed']);
        AuditService::log('ORDER_CLOSED', 'purchase_order', $po->id, ['status' => 'received'], ['status' => 'closed']);
    }

    private function n(float|string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    private function notify(int $userId, string $message, int $invoiceId): void
    {
        AppNotification::create(['user_id' => $userId, 'message' => $message, 'link' => "/invoices/{$invoiceId}"]);
    }
}