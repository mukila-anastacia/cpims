<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    /** Everything the comparison screen needs (VQM-02). */
    public function comparison(PurchaseRequest $r): array
    {
        $r->load(['branch', 'category', 'lines.item', 'quotations.vendor', 'quotations.lines']);
        $lines = $r->lines;

        $quotes = $r->quotations->sortBy('id')->map(function (Quotation $q) use ($lines) {
            $prices = $q->lines->mapWithKeys(fn ($l) => [$l->request_line_id => (float) $l->unit_price]);
            $total = $lines->sum(fn ($l) => (float) $l->quantity * (float) ($prices[$l->id] ?? 0));

            return [
                'id' => $q->id,
                'vendor_id' => $q->vendor_id,
                'vendor' => $q->vendor->name,
                'vendor_status' => $q->vendor->status,
                'delivery_days' => $q->delivery_days,
                'valid_until' => $q->valid_until?->format('Y-m-d'),
                'is_selected' => (bool) $q->is_selected,
                'reason_not_lowest' => $q->reason_not_lowest,
                'prices' => $prices->all(),
                'total' => round($total, 2),
            ];
        })->values();

        $lowest = $quotes->isEmpty() ? null : $quotes->min('total');
        $quotes = $quotes->map(fn ($q) => $q + ['is_lowest' => $lowest !== null && abs($q['total'] - $lowest) < 0.005])->values();

        $threshold = (float) Setting::get('quotation_threshold', 100000);
        $min = (int) Setting::get('min_quotations', 3);

        return [
            'request' => [
                'id' => $r->id,
                'request_no' => $r->request_no,
                'status' => $r->status,
                'branch' => $r->branch->name,
                'category' => $r->category->name,
                'total_estimate' => (float) $r->total_estimate,
            ],
            'lines' => $lines->map(function ($l) use ($quotes, $r) {
                $prices = $quotes->map(fn ($q) => $q['prices'][$l->id] ?? null)->filter()->values();

                return [
                    'id' => $l->id,
                    'item' => $l->item->name,
                    'unit' => $l->item->unit,
                    'quantity' => (float) $l->quantity,
                    'lowest_price' => $prices->isEmpty() ? null : $prices->min(),
                    'history' => $this->history($l->item_id, $r->id),
                ];
            })->values(),
            'quotations' => $quotes,
            'lowest_total' => $lowest,
            'rules' => [
                'threshold' => $threshold,
                'min_quotations' => $min,
                'applies' => (float) $r->total_estimate > $threshold,
                'count' => $quotes->count(),
            ],
            'locked' => $r->status !== 'approved',
        ];
    }

    /** Recent prices for the same item from other requests, any branch: orders first, then quotes. */
    private function history(int $itemId, int $excludeRequestId): array
    {
        $orders = DB::table('po_lines as p')
            ->join('purchase_orders as o', 'o.id', '=', 'p.purchase_order_id')
            ->join('vendors as v', 'v.id', '=', 'o.vendor_id')
            ->join('purchase_requests as r', 'r.id', '=', 'o.purchase_request_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->where('p.item_id', $itemId)->where('o.purchase_request_id', '!=', $excludeRequestId)
            ->orderByDesc('o.issued_at')->limit(3)
            ->select('p.unit_price', 'v.name as vendor', 'b.name as branch', 'o.issued_at as at', DB::raw("'ordered' as kind"))
            ->get();

        $quotes = DB::table('quotation_lines as ql')
            ->join('request_lines as rl', 'rl.id', '=', 'ql.request_line_id')
            ->join('quotations as q', 'q.id', '=', 'ql.quotation_id')
            ->join('vendors as v', 'v.id', '=', 'q.vendor_id')
            ->join('purchase_requests as r', 'r.id', '=', 'q.purchase_request_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->where('rl.item_id', $itemId)->where('r.id', '!=', $excludeRequestId)
            ->orderByDesc('q.created_at')->limit(3)
            ->select('ql.unit_price', 'v.name as vendor', 'b.name as branch', 'q.created_at as at', DB::raw("'quoted' as kind"))
            ->get();

        return $orders->concat($quotes)->sortByDesc('at')->take(3)->map(fn ($h) => [
            'unit_price' => (float) $h->unit_price,
            'vendor' => $h->vendor,
            'branch' => $h->branch,
            'kind' => $h->kind,
            'date' => date('d/m/Y', strtotime($h->at)),
        ])->values()->all();
    }

    /** Add or change a quotation (VQM-02, VQM-03). */
    public function save(User $user, PurchaseRequest $r, array $data, ?Quotation $q = null): Quotation
    {
        if ($r->status !== 'approved') {
            abort(409, 'Quotations can only be recorded for approved requests that have no order yet.');
        }
        if ($q && $q->is_selected) {
            abort(409, 'The selected quotation cannot be changed. Select another one first.');
        }

        $r->load('lines');
        $vendor = Vendor::findOrFail($data['vendor_id']);

        if ($vendor->status !== 'active') {
            throw ValidationException::withMessages(['vendor_id' => 'This vendor is blocked and cannot be used.']);
        }
        $duplicate = Quotation::where('purchase_request_id', $r->id)->where('vendor_id', $vendor->id)
            ->when($q, fn ($x) => $x->where('id', '!=', $q->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['vendor_id' => 'This vendor already has a quotation for this request.']);
        }

        $given = collect($data['lines'])->keyBy('request_line_id');
        $ids = $r->lines->pluck('id');
        if ($ids->diff($given->keys())->isNotEmpty() || $given->keys()->diff($ids)->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Enter a price for every item on the request.']);
        }

        return DB::transaction(function () use ($user, $r, $data, $q, $vendor, $given) {
            $old = $q ? ['vendor' => $q->vendor->name, 'delivery_days' => $q->delivery_days] : null;

            if ($q) {
                $q->update(['vendor_id' => $vendor->id, 'delivery_days' => $data['delivery_days'] ?? null, 'valid_until' => $data['valid_until'] ?? null]);
                $q->lines()->delete();
            } else {
                $q = Quotation::create([
                    'purchase_request_id' => $r->id, 'vendor_id' => $vendor->id,
                    'delivery_days' => $data['delivery_days'] ?? null, 'valid_until' => $data['valid_until'] ?? null,
                ]);
            }
            foreach ($given as $lineId => $row) {
                $q->lines()->create(['request_line_id' => $lineId, 'unit_price' => $row['unit_price']]);
            }

            AuditService::log($old ? 'QUOTATION_UPDATED' : 'QUOTATION_ADDED', 'quotation', $q->id, $old,
                ['request' => $r->request_no, 'vendor' => $vendor->name, 'delivery_days' => $data['delivery_days'] ?? null]);

            return $q;
        });
    }

    public function remove(PurchaseRequest $r, Quotation $q): void
    {
        if ($r->status !== 'approved') {
            abort(409, 'Quotations can no longer be removed from this request.');
        }
        if ($q->is_selected) {
            abort(409, 'The selected quotation cannot be removed. Select another one first.');
        }
        DB::transaction(function () use ($r, $q) {
            AuditService::log('QUOTATION_REMOVED', 'quotation', $q->id, ['request' => $r->request_no, 'vendor' => $q->vendor->name], null);
            $q->lines()->delete();
            $q->delete();
        });
    }

    /** Choose the winning quotation. Enforces the minimum number of quotes (BR-06) and a reason when not the lowest. */
    public function select(User $user, PurchaseRequest $r, Quotation $q, ?string $reason): Quotation
    {
        if ($r->status !== 'approved') {
            abort(409, 'This request is not open for selecting a quotation.');
        }

        $r->load(['lines', 'quotations.lines', 'quotations.vendor']);
        $threshold = (float) Setting::get('quotation_threshold', 100000);
        $min = (int) Setting::get('min_quotations', 3);
        $count = $r->quotations->count();

        if ((float) $r->total_estimate > $threshold && $count < $min) {
            throw ValidationException::withMessages(['quotation' =>
                'This request is above KES ' . number_format($threshold) . " and needs at least $min quotations. Only $count recorded."]);
        }
        if ($q->vendor->status !== 'active') {
            throw ValidationException::withMessages(['quotation' => 'This vendor is blocked and cannot be selected.']);
        }

        $total = fn (Quotation $x) => $r->lines->sum(fn ($l) => (float) $l->quantity * (float) ($x->lines->firstWhere('request_line_id', $l->id)?->unit_price ?? 0));
        $lowest = $r->quotations->map($total)->min();
        $mine = $total($q);
        $reason = trim((string) $reason);

        if ($mine > $lowest + 0.004 && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why the lowest quotation was not chosen.']);
        }

        return DB::transaction(function () use ($r, $q, $mine, $lowest, $reason) {
            Quotation::where('purchase_request_id', $r->id)->update(['is_selected' => false, 'reason_not_lowest' => null]);
            $q->update(['is_selected' => true, 'reason_not_lowest' => $mine > $lowest + 0.004 ? $reason : null]);

            AuditService::log('QUOTATION_SELECTED', 'quotation', $q->id, null, [
                'request' => $r->request_no, 'vendor' => $q->vendor->name, 'total' => round($mine, 2),
                'lowest_total' => round($lowest, 2), 'reason' => $mine > $lowest + 0.004 ? $reason : null,
            ]);

            return $q->fresh();
        });
    }
}