<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private const WITH = ['vendor', 'issuer', 'purchaseRequest.branch', 'lines.item', 'receipts.lines', 'receipts.receiver', 'receipts.overrideUser'];

    public function __construct(private OrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = $this->scoped($request)->with(['vendor', 'purchaseRequest.branch'])->latest('id');

        $status = $request->query('status');
        if ($status === 'awaiting') {
            $q->whereIn('status', ['issued', 'partly_received']);
        } elseif (in_array($status, ['issued', 'partly_received', 'received', 'closed', 'cancelled'], true)) {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn ($o) => [
            'id' => $o->id,
            'po_no' => $o->po_no,
            'request_id' => $o->purchase_request_id,
            'request_no' => $o->purchaseRequest->request_no,
            'branch' => $o->purchaseRequest->branch->name,
            'vendor' => $o->vendor->name,
            'total' => (float) $o->total,
            'status' => $o->status,
            'expected_delivery' => $o->expected_delivery?->format('Y-m-d'),
            'issued_at' => $o->issued_at?->toIso8601String(),
        ])->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $po = $this->scoped($request)->with(self::WITH)->findOrFail($id);

        return response()->json(['data' => $this->present($po, $request->user())]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $po = $this->service->create($request->user(), PurchaseRequest::findOrFail($id));

        return response()->json(['data' => $this->present($this->fresh($po->id), $request->user())], 201);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'delivery_note_no' => ['nullable', 'string', 'max:40'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.qty_received' => ['required', 'numeric', 'gte:0', 'max:100000'],
            'lines.*.item_condition' => ['required', 'in:good,damaged,short'],
        ]);

        $po = $this->scoped($request)->with('purchaseRequest')->findOrFail($id);
        $this->service->receive($request->user(), $po, $data);

        return response()->json(['data' => $this->present($this->fresh($id), $request->user())], 201);
    }

    /** Which orders a user may see: branch staff only their own branch (UAM-03). */
    private function scoped(Request $request)
    {
        $u = $request->user();
        $q = PurchaseOrder::query();

        return match ($u->role->name) {
            'Requester', 'Branch Manager' => $q->whereHas('purchaseRequest', fn ($r) => $r->where('branch_id', $u->branch_id)),
            'System Administrator' => $q->whereRaw('1 = 0'),
            default => $q,
        };
    }

    private function fresh(int $id): PurchaseOrder
    {
        return PurchaseOrder::with(self::WITH)->findOrFail($id);
    }

    private function present(PurchaseOrder $po, $user): array
    {
        $received = $po->receipts->flatMap->lines->groupBy('po_line_id')->map(fn ($g) => (float) $g->sum('qty_received'));
        $rights = $this->service->receiveRights($user, $po);

        return [
            'id' => $po->id,
            'po_no' => $po->po_no,
            'status' => $po->status,
            'request_id' => $po->purchase_request_id,
            'request_no' => $po->purchaseRequest->request_no,
            'branch' => $po->purchaseRequest->branch->name,
            'vendor' => [
                'name' => $po->vendor->name, 'tax_pin' => $po->vendor->tax_pin, 'contact_person' => $po->vendor->contact_person,
                'phone' => $po->vendor->phone, 'email' => $po->vendor->email,
            ],
            'total' => (float) $po->total,
            'issued_at' => $po->issued_at?->toIso8601String(),
            'issued_by' => $po->issuer->full_name,
            'expected_delivery' => $po->expected_delivery?->format('Y-m-d'),
            'lines' => $po->lines->map(function ($l) use ($received) {
                $got = (float) ($received[$l->id] ?? 0);

                return [
                    'id' => $l->id,
                    'item' => $l->item->name,
                    'unit' => $l->item->unit,
                    'ordered' => (float) $l->quantity,
                    'received' => $got,
                    'remaining' => max(0, (float) $l->quantity - $got),
                    'unit_price' => (float) $l->unit_price,
                    'line_total' => round((float) $l->quantity * (float) $l->unit_price, 2),
                ];
            })->values(),
            'receipts' => $po->receipts->sortByDesc('id')->map(fn ($g) => [
                'receipt_no' => $g->receipt_no,
                'received_at' => $g->received_at?->toIso8601String(),
                'received_by' => $g->receiver->full_name,
                'delivery_note_no' => $g->delivery_note_no,
                'override' => (bool) $g->override_by,
                'override_reason' => $g->override_reason,
                'lines' => $g->lines->map(fn ($rl) => [
                    'item' => $po->lines->firstWhere('id', $rl->po_line_id)?->item->name,
                    'qty' => (float) $rl->qty_received,
                    'condition' => $rl->item_condition,
                ])->values(),
            ])->values(),
            'rights' => ['can_receive' => $rights['allowed'], 'override' => $rights['override'], 'note' => $rights['note']],
        ];
    }
}