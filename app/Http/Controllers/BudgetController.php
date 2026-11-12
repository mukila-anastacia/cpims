<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PurchaseRequest;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Budget::with(['branch', 'category'])->orderBy('branch_id')->orderBy('category_id')->orderBy('period_start');

        if ($request->query('period', 'current') === 'current') {
            $today = now()->toDateString();
            $q->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today);
        }
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->query('category_id'));
        }

        return response()->json([
            'data' => $q->limit(500)->get()->map(fn ($b) => $this->present($b))->values(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guard($data);

        $b = Budget::create($data)->load(['branch', 'category']);
        AuditService::log('BUDGET_CREATED', 'budget', $b->id, null, $this->snapshot($b));

        return response()->json(['data' => $this->present($b)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $b = Budget::with(['branch', 'category'])->findOrFail($id);
        $old = $this->snapshot($b);

        $data = $this->validated($request);
        $this->guard($data, $b->id);

        $b->update($data);
        $b->refresh()->load(['branch', 'category']);
        AuditService::log('BUDGET_UPDATED', 'budget', $b->id, $old, $this->snapshot($b));

        return response()->json(['data' => $this->present($b)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
        ], [
            'period_end.after' => 'The end date must be after the start date.',
        ]);
    }

    /** One budget per branch and category at any time, so the remaining-budget check is never ambiguous. */
    private function guard(array $data, ?int $ignoreId = null): void
    {
        $overlap = Budget::where('branch_id', $data['branch_id'])->where('category_id', $data['category_id'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('period_start', '<=', $data['period_end'])
            ->whereDate('period_end', '>=', $data['period_start'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['period_start' => 'This branch and category already has a budget that overlaps these dates.']);
        }
    }

    /** Approved requests and later count against the budget, as in the request budget check. */
    private function committed(Budget $b): float
    {
        return (float) PurchaseRequest::where('branch_id', $b->branch_id)->where('category_id', $b->category_id)
            ->whereIn('status', ['approved', 'ordered', 'received', 'closed'])
            ->whereBetween('submitted_at', [$b->period_start->copy()->startOfDay(), $b->period_end->copy()->endOfDay()])
            ->sum('total_estimate');
    }

    private function snapshot(Budget $b): array
    {
        return [
            'branch' => $b->branch->name, 'category' => $b->category->name,
            'period_start' => $b->period_start->format('Y-m-d'), 'period_end' => $b->period_end->format('Y-m-d'),
            'amount' => (float) $b->amount,
        ];
    }

    private function present(Budget $b): array
    {
        $amount = (float) $b->amount;
        $committed = $this->committed($b);
        $today = now()->startOfDay();

        return [
            'id' => $b->id,
            'branch_id' => $b->branch_id,
            'branch' => $b->branch->name,
            'category_id' => $b->category_id,
            'category' => $b->category->name,
            'period_start' => $b->period_start->format('Y-m-d'),
            'period_end' => $b->period_end->format('Y-m-d'),
            'amount' => $amount,
            'committed' => $committed,
            'remaining' => $amount - $committed,
            'used_pct' => $amount > 0 ? round($committed / $amount * 100, 1) : 0,
            'current' => $b->period_start->lte($today) && $b->period_end->gte($today),
        ];
    }
}