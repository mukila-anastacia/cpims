<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestService
{
    /** Create or update a draft with its lines (PRA-01). */
    public function saveDraft(User $user, array $data, ?PurchaseRequest $request = null): PurchaseRequest
    {
        return DB::transaction(function () use ($user, $data, $request) {
            $isNew = $request === null;
            $old = $isNew ? null : $request->only(['category_id', 'needed_by', 'justification', 'total_estimate', 'status']);

            if ($isNew) {
                $request = PurchaseRequest::create([
                    'request_no' => 'TMP-' . uniqid(),
                    'branch_id' => $user->branch_id,
                    'category_id' => $data['category_id'],
                    'requester_id' => $user->id,
                    'status' => 'draft',
                ]);
                $request->update(['request_no' => sprintf('PR-%04d', $request->id)]);
            } else {
                $request->lines()->delete(); // only drafts or returned requests reach here, nothing else points at these lines
            }

            $total = 0;
            foreach ($data['lines'] as $line) {
                $request->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost_est' => $line['unit_cost_est'],
                    'note' => $line['note'] ?? null,
                ]);
                $total += $line['quantity'] * $line['unit_cost_est'];
            }

            $request->update([
                'category_id' => $data['category_id'],
                'needed_by' => $data['needed_by'] ?? null,
                'justification' => $data['justification'] ?? null,
                'total_estimate' => round($total, 2),
            ]);

            AuditService::log(
                $isNew ? 'REQUEST_CREATED' : 'REQUEST_UPDATED',
                'purchase_request',
                $request->id,
                $old,
                $request->only(['category_id', 'needed_by', 'justification', 'total_estimate', 'status'])
            );

            return $request;
        });
    }

    /** Remaining budget for a branch and category today (PRA-03). Counts approved requests and later. */
    public function budgetStatus(int $branchId, int $categoryId, float $amount = 0, ?int $excludeRequestId = null): array
    {
        $today = now()->toDateString();
        $budget = Budget::where('branch_id', $branchId)->where('category_id', $categoryId)
            ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->first();

        if (! $budget) {
            return ['has_budget' => false, 'budget' => 0.0, 'committed' => 0.0, 'remaining' => 0.0, 'within_budget' => false];
        }

        $committed = (float) PurchaseRequest::where('branch_id', $branchId)->where('category_id', $categoryId)
            ->whereIn('status', ['approved', 'ordered', 'received', 'closed'])
            ->whereBetween('submitted_at', [$budget->period_start->copy()->startOfDay(), $budget->period_end->copy()->endOfDay()])
            ->when($excludeRequestId, fn ($q) => $q->where('id', '!=', $excludeRequestId))
            ->sum('total_estimate');

        $remaining = (float) $budget->amount - $committed;

        return [
            'has_budget' => true,
            'budget' => (float) $budget->amount,
            'committed' => $committed,
            'remaining' => $remaining,
            'within_budget' => $amount <= $remaining,
        ];
    }

    /** Same items requested by any branch within the duplicate window (DUP-01, BR-04). */
    public function findDuplicates(PurchaseRequest $request): array
    {
        $since = now()->subDays((int) Setting::get('duplicate_window_days', 14));
        $itemIds = $request->lines->pluck('item_id')->all();

        return DB::table('request_lines as l')
            ->join('purchase_requests as r', 'r.id', '=', 'l.purchase_request_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->whereIn('l.item_id', $itemIds)
            ->where('r.id', '!=', $request->id)
            ->whereNotIn('r.status', ['draft', 'rejected', 'cancelled'])
            ->where('r.created_at', '>=', $since)
            ->orderByDesc('r.created_at')
            ->select('r.request_no', 'b.name as branch', 'i.name as item', 'l.quantity', 'r.created_at')
            ->get()
            ->map(fn ($r) => [
                'request_no' => $r->request_no,
                'branch' => $r->branch,
                'item' => $r->item,
                'quantity' => (float) $r->quantity,
                'date' => Carbon::parse($r->created_at)->format('d/m/Y'),
            ])->all();
    }

    /** Check, route and submit a request for approval (PRA-02 to PRA-04, DUP-02, BR-01, BR-03). */
    public function submit(User $user, PurchaseRequest $request, ?string $duplicateJustification): PurchaseRequest
    {
        if (! in_array($request->status, ['draft', 'returned'], true)) {
            abort(409, 'This request has already been submitted.');
        }

        $request->load(['lines', 'requester', 'branch']);

        $errors = [];
        if ($request->lines->isEmpty()) { $errors['lines'] = 'Add at least one item.'; }
        if (! trim((string) $request->justification)) { $errors['justification'] = 'Enter a justification.'; }
        if ($errors) { throw ValidationException::withMessages($errors); }

        $duplicates = $this->findDuplicates($request);
        if ($duplicates && ! trim((string) $duplicateJustification)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Similar items were requested recently. Add a justification to continue.',
                'code' => 'DUPLICATE_JUSTIFICATION_REQUIRED',
                'duplicates' => $duplicates,
            ], 422));
        }

        return DB::transaction(function () use ($request, $duplicates, $duplicateJustification) {
            $total = (float) $request->total_estimate;
            $budget = $this->budgetStatus($request->branch_id, $request->category_id, $total, $request->id);
            $exception = ! $budget['within_budget'];

            // BR-01: every level whose lower limit is below the request value must approve
            $rules = ApprovalRule::where('is_active', true)->where('min_value', '<', $total)->orderBy('level')->get();
            if ($rules->isEmpty()) {
                $rules = ApprovalRule::where('is_active', true)->orderBy('level')->limit(1)->get();
            }
            $steps = $rules->map(fn ($r) => $r->role_id)->values()->all();

            // BR-03: a request over budget also needs Finance
            $financeId = Role::where('name', 'Finance Officer')->value('id');
            if ($exception && ! in_array($financeId, $steps, true)) {
                $steps[] = $financeId;
            }
                        // A resubmitted request starts a new approval round; earlier decisions stay as history
            Approval::where('purchase_request_id', $request->id)->where('decision', 'pending')->delete();

            $approvals = [];
            foreach ($steps as $i => $roleId) {
                $approver = $this->findApprover($roleId, $request->branch_id, $request->requester_id);
                if (! $approver) {
                    $role = Role::find($roleId)->name;
                    throw ValidationException::withMessages(['approval' => "No active $role is set up to approve this request. Ask the administrator."]);
                }
                $approvals[] = Approval::create([
                    'purchase_request_id' => $request->id,
                    'level' => $i + 1,
                    'approver_id' => $approver->id,
                    'decision' => 'pending',
                ]);
            }

            $oldStatus = $request->status;
            $request->update([
                'status' => 'in_approval',
                'current_level' => 1,
                'duplicate_flag' => (bool) $duplicates,
                'duplicate_justification' => $duplicates ? trim((string) $duplicateJustification) : null,
                'budget_exception' => $exception,
                'submitted_at' => now(),
            ]);

            AppNotification::create([
                'user_id' => $approvals[0]->approver_id,
                'message' => "Request {$request->request_no} from {$request->requester->full_name} ({$request->branch->name}) needs your approval.",
                'link' => "/requests/{$request->id}",
            ]);

            AuditService::log('REQUEST_SUBMITTED', 'purchase_request', $request->id,
                ['status' => $oldStatus],
                ['status' => 'in_approval', 'levels' => count($approvals), 'budget_exception' => $exception, 'duplicate_flag' => (bool) $duplicates]
            );

            return $request;
        });
    }

    public function cancel(PurchaseRequest $request): PurchaseRequest
    {
        if (! in_array($request->status, ['draft', 'returned', 'in_approval'], true)) {
            abort(409, 'This request can no longer be cancelled.');
        }
        $old = $request->status;
        $request->update(['status' => 'cancelled']);
        AuditService::log('REQUEST_CANCELLED', 'purchase_request', $request->id, ['status' => $old], ['status' => 'cancelled']);

        return $request;
    }

    /** First active user with the role. Branch Managers must belong to the request's branch. A deputy replaces an absent approver. */
    private function findApprover(int $roleId, int $branchId, int $requesterId): ?User
    {
        $roleName = Role::find($roleId)->name;
        $query = User::where('role_id', $roleId)->where('status', 'active')->where('id', '!=', $requesterId);
        if ($roleName === 'Branch Manager') {
            $query->where('branch_id', $branchId);
        }
        $user = $query->orderBy('id')->first();

        if ($user && $user->deputy_user_id && $user->deputy_until && $user->deputy_until->gte(today())) {
            $deputy = User::find($user->deputy_user_id);
            if ($deputy && $deputy->status === 'active' && $deputy->id !== $requesterId) {
                return $deputy;
            }
        }

        return $user;
    }
}