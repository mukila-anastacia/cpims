<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportService
{
    private const APPROVED = ['approved', 'ordered', 'received', 'closed'];

    /** Branch Managers are always limited to their own branch. */
    public function branchFor(User $user, ?int $requested): ?int
    {
        return $user->hasRole('Branch Manager') ? $user->branch_id : $requested;
    }

    private function requests(?string $from, ?string $to, ?int $branch)
    {
        return PurchaseRequest::query()->whereNotNull('submitted_at')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->when($from, fn ($q) => $q->whereDate('submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('submitted_at', '<=', $to));
    }

    private function orders(?string $from, ?string $to, ?int $branch)
    {
        return PurchaseOrder::query()->where('status', '!=', 'cancelled')
            ->when($branch, fn ($q) => $q->whereHas('purchaseRequest', fn ($r) => $r->where('branch_id', $branch)))
            ->when($from, fn ($q) => $q->whereDate('issued_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issued_at', '<=', $to));
    }

    /** When the last approval of the current submission was given (timestamp). */
    private function finalApproval(PurchaseRequest $r): ?int
    {
        $times = $r->approvals
            ->where('decision', 'approved')
            ->filter(fn ($a) => $a->decided_at && $a->decided_at->timestamp >= $r->submitted_at->timestamp)
            ->map(fn ($a) => $a->decided_at->timestamp);

        return $times->isEmpty() ? null : $times->max();
    }

    private function stats(Collection $hours): array
    {
        $n = $hours->count();
        $avg = $n ? $hours->avg() : null;

        return [
            'count' => $n,
            'avg_hours' => $avg === null ? null : round($avg, 2),
            'avg_days' => $avg === null ? null : round($avg / 24, 2),
        ];
    }

    /** The five project indicators plus spend, status and ageing (RPT-01, RPT-02). */
    public function summary(User $user, ?string $from, ?string $to, ?int $requestedBranch): array
    {
        $started = microtime(true);
        $branch = $this->branchFor($user, $requestedBranch);

        $reqs = $this->requests($from, $to, $branch)->with(['approvals', 'purchaseOrder'])->get();
        $submitted = $reqs->count();

        $turnaround = $reqs->filter(fn ($r) => in_array($r->status, self::APPROVED, true))
            ->map(fn ($r) => ($t = $this->finalApproval($r)) === null ? null : max(0, ($t - $r->submitted_at->timestamp) / 3600))
            ->filter(fn ($v) => $v !== null)->values();

        $toOrder = $reqs->filter(fn ($r) => $r->purchaseOrder)
            ->map(fn ($r) => max(0, ($r->purchaseOrder->issued_at->timestamp - $r->submitted_at->timestamp) / 3600))->values();

        $exceptions = $reqs->where('budget_exception', true)->count();
        $duplicates = $reqs->where('duplicate_flag', true)->count();

        $orders = $this->orders($from, $to, $branch)->with(['vendor', 'purchaseRequest.branch'])->get();
        $spend = fn (Collection $g) => $g->map(fn ($rows, $name) => ['name' => $name, 'total' => round((float) $rows->sum('total'), 2), 'orders' => $rows->count()])
            ->sortByDesc('total')->values()->take(12)->all();

        $pending = Approval::where('decision', 'pending')
            ->whereHas('purchaseRequest', fn ($q) => $q->where('status', 'in_approval')
                ->whereColumn('purchase_requests.current_level', 'approvals.level')
                ->when($branch, fn ($x) => $x->where('branch_id', $branch)))
            ->with('purchaseRequest')->get();
        $ageing = ['d0_1' => 0, 'd2_3' => 0, 'd4_plus' => 0];
        foreach ($pending as $a) {
            $days = (int) floor((now()->timestamp - $a->purchaseRequest->submitted_at->timestamp) / 86400);
            $ageing[$days <= 1 ? 'd0_1' : ($days <= 3 ? 'd2_3' : 'd4_plus')]++;
        }

        $summary = [
            'filters' => ['from' => $from, 'to' => $to, 'branch_id' => $branch],
            'indicators' => [
                'submitted' => $submitted,
                'approval_turnaround' => $this->stats($turnaround),
                'request_to_order' => $this->stats($toOrder),
                'budget_compliance' => [
                    'within' => $submitted - $exceptions,
                    'exceptions' => $exceptions,
                    'pct' => $submitted ? round(($submitted - $exceptions) / $submitted * 100, 1) : null,
                ],
                'duplicates' => [
                    'flagged' => $duplicates,
                    'pct' => $submitted ? round($duplicates / $submitted * 100, 1) : null,
                ],
            ],
            'baseline' => ['approval_turnaround' => '2 to 4 working days (preliminary investigation at A-Solar)'],
            'by_status' => $reqs->groupBy('status')->map->count()->all(),
            'spend_by_branch' => $spend($orders->groupBy(fn ($o) => $o->purchaseRequest->branch->name)),
            'spend_by_vendor' => $spend($orders->groupBy(fn ($o) => $o->vendor->name)),
            'ageing' => $ageing,
            'open_invoice_exceptions' => $user->hasRole('Branch Manager') ? null : Invoice::where('match_status', 'exception')->count(),
            'branches' => $user->hasRole('Branch Manager') ? [] : Branch::orderBy('name')->get(['id', 'name']),
        ];

        // The reporting-time indicator: how long the system needed to produce this consolidated report
        $summary['indicators']['reporting_time_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $summary;
    }

    /** Header and rows for a CSV export. */
    public function export(string $type, ?string $from, ?string $to, ?int $branch): array
    {
        return match ($type) {
            'requests' => $this->requestRows($from, $to, $branch),
            'orders' => $this->orderRows($from, $to, $branch),
            'invoices' => $this->invoiceRows($from, $to, $branch),
        };
    }

    private function requestRows(?string $from, ?string $to, ?int $branch): array
    {
        $rows = $this->requests($from, $to, $branch)->with(['branch', 'category', 'requester', 'approvals', 'purchaseOrder'])->orderBy('id')->get()
            ->map(fn ($r) => [
                $r->request_no, $r->branch->name, $r->category->name, $r->requester->full_name, $r->status,
                number_format((float) $r->total_estimate, 2, '.', ''),
                $r->submitted_at->format('Y-m-d H:i'),
                ($t = $this->finalApproval($r)) ? date('Y-m-d H:i', $t) : '',
                $r->purchaseOrder?->issued_at?->format('Y-m-d H:i') ?? '',
                $r->duplicate_flag ? 'Yes' : 'No',
                $r->budget_exception ? 'Yes' : 'No',
            ])->all();

        return [['Request no', 'Branch', 'Category', 'Requested by', 'Status', 'Estimate (KES)', 'Submitted', 'Final approval', 'Order issued', 'Duplicate flag', 'Over budget'], $rows];
    }

    private function orderRows(?string $from, ?string $to, ?int $branch): array
    {
        $rows = $this->orders($from, $to, $branch)->with(['vendor', 'purchaseRequest.branch'])->orderBy('id')->get()
            ->map(fn ($o) => [
                $o->po_no, $o->purchaseRequest->request_no, $o->purchaseRequest->branch->name, $o->vendor->name,
                number_format((float) $o->total, 2, '.', ''), $o->status,
                $o->issued_at->format('Y-m-d'), $o->expected_delivery?->format('Y-m-d') ?? '',
            ])->all();

        return [['Order no', 'Request', 'Branch', 'Vendor', 'Total (KES)', 'Status', 'Issued', 'Expected delivery'], $rows];
    }

    private function invoiceRows(?string $from, ?string $to, ?int $branch): array
    {
        $rows = Invoice::query()->with(['vendor', 'purchaseOrder.purchaseRequest.branch', 'enteredBy'])
            ->when($branch, fn ($q) => $q->whereHas('purchaseOrder.purchaseRequest', fn ($r) => $r->where('branch_id', $branch)))
            ->when($from, fn ($q) => $q->whereDate('invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('invoice_date', '<=', $to))
            ->orderBy('id')->get()
            ->map(fn ($i) => [
                $i->invoice_no, $i->purchaseOrder->po_no, $i->vendor->name, $i->purchaseOrder->purchaseRequest->branch->name,
                $i->invoice_date->format('Y-m-d'), number_format((float) $i->total, 2, '.', ''), $i->match_status, $i->enteredBy->full_name,
            ])->all();

        return [['Invoice no', 'Order', 'Vendor', 'Branch', 'Invoice date', 'Total (KES)', 'Match status', 'Entered by'], $rows];
    }
}