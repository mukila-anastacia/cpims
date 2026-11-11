<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Setting;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function __construct(private QuotationService $service) {}

    /** Approved requests waiting for quotations or for a vendor to be selected. */
    public function queue(): JsonResponse
    {
        $threshold = (float) Setting::get('quotation_threshold', 100000);
        $min = (int) Setting::get('min_quotations', 3);

        $rows = PurchaseRequest::with(['branch', 'category', 'quotations'])
            ->where('status', 'approved')->orderBy('updated_at')->get()
            ->map(function ($r) use ($threshold, $min) {
                $needed = (float) $r->total_estimate > $threshold ? $min : 1;

                return [
                    'request_id' => $r->id,
                    'request_no' => $r->request_no,
                    'branch' => $r->branch->name,
                    'category' => $r->category->name,
                    'total_estimate' => (float) $r->total_estimate,
                    'quotations_count' => $r->quotations->count(),
                    'needed' => $needed,
                    'selected' => $r->quotations->contains('is_selected', true),
                    'needed_by' => $r->needed_by?->format('Y-m-d'),
                ];
            });

        return response()->json(['data' => $rows->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $r = PurchaseRequest::findOrFail($id);
        if (! in_array($r->status, ['approved', 'ordered', 'received', 'closed'], true)) {
            abort(409, 'Quotations are available once a request is approved.');
        }

        return response()->json($this->service->comparison($r));
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $r = PurchaseRequest::findOrFail($id);
        $this->service->save($request->user(), $r, $this->validated($request));

        return response()->json($this->service->comparison($r->fresh()), 201);
    }

    public function update(Request $request, int $id, int $qid): JsonResponse
    {
        $r = PurchaseRequest::findOrFail($id);
        $q = Quotation::where('purchase_request_id', $r->id)->findOrFail($qid);
        $this->service->save($request->user(), $r, $this->validated($request), $q);

        return response()->json($this->service->comparison($r->fresh()));
    }

    public function destroy(int $id, int $qid): JsonResponse
    {
        $r = PurchaseRequest::findOrFail($id);
        $this->service->remove($r, Quotation::where('purchase_request_id', $r->id)->findOrFail($qid));

        return response()->json($this->service->comparison($r->fresh()));
    }

    public function select(Request $request, int $id, int $qid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $r = PurchaseRequest::findOrFail($id);
        $this->service->select($request->user(), $r, Quotation::where('purchase_request_id', $r->id)->findOrFail($qid), $data['reason'] ?? null);

        return response()->json($this->service->comparison($r->fresh()));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'delivery_days' => ['nullable', 'integer', 'between:0,365'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.request_line_id' => ['required', 'integer'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ]);
    }
}