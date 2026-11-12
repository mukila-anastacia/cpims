<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Services\RequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    private const FULL = ['branch', 'requester', 'category', 'lines.item', 'approvals.approver.role'];

    public function __construct(private RequestService $service) {}

    public function lookups(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'items' => Item::where('is_active', true)->orderBy('name')->get(['id', 'category_id', 'name', 'unit']),
        ]);
    }

    public function budget(Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'exists:categories,id']]);

        return response()->json($this->service->budgetStatus($request->user()->branch_id, (int) $data['category_id']));
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->scoped($request)->with(['branch', 'requester', 'category'])->latest('id');
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return response()->json(['data' => $q->limit(200)->get()->map(fn ($r) => $this->present($r, $request->user()))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $r = $this->scoped($request)->with(self::FULL)->findOrFail($id);

        return response()->json(['data' => $this->present($r, $request->user(), true)]);
    }

    public function checks(Request $request, int $id): JsonResponse
    {
        $r = $this->scoped($request)->with('lines')->findOrFail($id);

        return response()->json([
            'budget' => $this->service->budgetStatus($r->branch_id, $r->category_id, (float) $r->total_estimate, $r->id),
            'duplicates' => $this->service->findDuplicates($r),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $r = $this->service->saveDraft($request->user(), $this->validated($request));

        return response()->json(['data' => $this->present($r->load(self::FULL), $request->user(), true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $r = $this->mine($request, $id);
        if (! in_array($r->status, ['draft', 'returned'], true)) {
            abort(409, 'This request can no longer be edited.');
        }
        $r = $this->service->saveDraft($request->user(), $this->validated($request), $r);

        return response()->json(['data' => $this->present($r->load(self::FULL), $request->user(), true)]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $r = $this->service->submit($request->user(), $this->mine($request, $id), $request->input('duplicate_justification'));

        return response()->json(['data' => $this->present($r->load(self::FULL), $request->user(), true)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = $this->service->cancel($this->mine($request, $id));

        return response()->json(['data' => $this->present($r->load(self::FULL), $request->user(), true)]);
    }

    /** Which requests a user may see (UAM-03). Drafts stay private to their owner. */
    private function scoped(Request $request)
    {
        $u = $request->user();
        $q = PurchaseRequest::query();

        $q = match ($u->role->name) {
            'Requester' => $q->where('requester_id', $u->id),
            'Branch Manager' => $q->where('branch_id', $u->branch_id),
            'System Administrator' => $q->whereRaw('1 = 0'),
            default => $q,
        };

        return $q->where(fn ($w) => $w->where('status', '!=', 'draft')->orWhere('requester_id', $u->id));
    }

    private function mine(Request $request, int $id): PurchaseRequest
    {
        return PurchaseRequest::where('requester_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'justification' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'exists:items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'lines.*.unit_cost_est' => ['required', 'numeric', 'gte:0', 'max:100000000'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        $wrongCategory = Item::whereIn('id', collect($data['lines'])->pluck('item_id'))
            ->where('category_id', '!=', $data['category_id'])->exists();
        if ($wrongCategory) {
            throw ValidationException::withMessages(['lines' => 'Every item must belong to the chosen category.']);
        }

        return $data;
    }

    private function present(PurchaseRequest $r, $user, bool $full = false): array
    {
        $own = $r->requester_id === $user->id;
        $out = [
            'id' => $r->id,
            'request_no' => $r->request_no,
            'status' => $r->status,
                           'current_level' => (int) $r->current_level,
            'category' => $r->category->name,
            'category_id' => $r->category_id,
            'branch' => $r->branch->name,
            'requester' => $r->requester->full_name,
            'total_estimate' => (float) $r->total_estimate,
            'needed_by' => $r->needed_by?->format('Y-m-d'),
            'created_at' => $r->created_at?->toIso8601String(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'justification' => $r->justification,
            'duplicate_flag' => (bool) $r->duplicate_flag,
            'duplicate_justification' => $r->duplicate_justification,
            'budget_exception' => (bool) $r->budget_exception,
            'can_edit' => $own && in_array($r->status, ['draft', 'returned'], true),
            'can_cancel' => $own && in_array($r->status, ['draft', 'returned', 'in_approval'], true),
            'can_quote' => in_array($r->status, ['approved', 'ordered', 'received', 'closed'], true) && $user->hasRole('Procurement Administrator', 'Finance Officer', 'Head Office Manager'),
        ];

        if ($full) {
            $out['order_id'] = $r->purchaseOrder?->id;
            $out['lines'] = $r->lines->map(fn ($l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'item' => $l->item->name,
                'unit' => $l->item->unit,
                'quantity' => (float) $l->quantity,
                'unit_cost_est' => (float) $l->unit_cost_est,
                'note' => $l->note,
            ])->values();
            $out['approvals'] = $r->approvals->sortBy('id')->map(fn ($a) => [
                'level' => $a->level,
                'approver' => $a->approver->full_name,
                'role' => $a->approver->role->name,
                'decision' => $a->decision,
                'comment' => $a->comment,
                'decided_at' => $a->decided_at?->toIso8601String(),
            ])->values();
                           $out['can_decide'] = $r->status === 'in_approval' && ! $own
                   && $r->approvals->contains(fn ($a) => $a->approver_id === $user->id && $a->decision === 'pending' && $a->level === (int) $r->current_level);
        }

        return $out;
    }
}