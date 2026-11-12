<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = Invoice::with(['vendor', 'purchaseOrder.purchaseRequest.branch', 'enteredBy'])->latest('id');
        if (in_array($request->query('status'), ['pending', 'matched', 'exception', 'resolved'], true)) {
            $q->where('match_status', $request->query('status'));
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn ($i) => [
            'id' => $i->id,
            'invoice_no' => $i->invoice_no,
            'invoice_date' => $i->invoice_date?->format('Y-m-d'),
            'po_no' => $i->purchaseOrder->po_no,
            'branch' => $i->purchaseOrder->purchaseRequest->branch->name,
            'vendor' => $i->vendor->name,
            'total' => (float) $i->total,
            'match_status' => $i->match_status,
            'entered_by' => $i->enteredBy->full_name,
        ])->values()]);
    }

    /** Orders that have received goods not yet invoiced: the choices on the invoice form. */
    public function orders(): JsonResponse
    {
        $rows = PurchaseOrder::with(['vendor', 'purchaseRequest.branch', 'lines.item'])
            ->whereIn('status', ['partly_received', 'received'])->orderBy('id')->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'vendor' => $po->vendor->name,
                'branch' => $po->purchaseRequest->branch->name,
                'lines' => $this->service->progress($po)->all(),
            ])
            ->filter(fn ($o) => collect($o['lines'])->contains(fn ($l) => $l['to_invoice'] > 0))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Invoice::findOrFail($id), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'invoice_no' => ['required', 'string', 'max:40'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ]);

        $inv = $this->service->record($request->user(), PurchaseOrder::findOrFail($data['purchase_order_id']), $data);

        return response()->json(['data' => $this->present($inv, $request->user())], 201);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $inv = $this->service->resolve($request->user(), Invoice::findOrFail($id), $data['comment'] ?? null);

        return response()->json(['data' => $this->present($inv, $request->user())]);
    }

    private function present(Invoice $i, $user): array
    {
        $i->loadMissing(['vendor', 'purchaseOrder.purchaseRequest.branch', 'enteredBy', 'resolvedBy']);

        return [
            'id' => $i->id,
            'invoice_no' => $i->invoice_no,
            'invoice_date' => $i->invoice_date?->format('Y-m-d'),
            'total' => (float) $i->total,
            'match_status' => $i->match_status,
            'resolution_comment' => $i->resolution_comment,
            'resolved_by' => $i->resolvedBy?->full_name,
            'entered_by' => $i->enteredBy->full_name,
            'po_id' => $i->purchase_order_id,
            'po_no' => $i->purchaseOrder->po_no,
            'request_no' => $i->purchaseOrder->purchaseRequest->request_no,
            'branch' => $i->purchaseOrder->purchaseRequest->branch->name,
            'vendor' => ['name' => $i->vendor->name, 'tax_pin' => $i->vendor->tax_pin],
            'tolerance' => (float) Setting::get('price_tolerance_pct', 2),
            'lines' => $this->service->evaluate($i)->all(),
            'can_resolve' => $i->match_status === 'exception' && $user->hasRole('Procurement Administrator'),
        ];
    }
}