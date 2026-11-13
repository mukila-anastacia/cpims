<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    /** Live counts for the home page, chosen by role (RPT-01). */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $branchOnly = $u->hasRole('Requester', 'Branch Manager');

        $awaitingDelivery = PurchaseOrder::whereIn('status', ['issued', 'partly_received'])
            ->when($branchOnly, fn ($q) => $q->whereHas('purchaseRequest', fn ($r) => $r->where('branch_id', $u->branch_id)))->count();
        $card = fn (string $key, string $label, int $value, string $link) => compact('key', 'label', 'value', 'link');
        $pending = $card('pending_approvals', 'Waiting for your approval', $this->approvals->pendingFor($u)->count(), '/approvals');
        $delivery = $card('awaiting_delivery', 'Orders awaiting delivery', $awaitingDelivery, '/orders');
        $exceptions = $card('invoice_exceptions', 'Invoice exceptions', Invoice::where('match_status', 'exception')->count(), '/invoices');

        $cards = match ($u->role->name) {
            'Requester' => [
                $card('drafts', 'My drafts', PurchaseRequest::where('requester_id', $u->id)->where('status', 'draft')->count(), '/requests'),
                $card('in_approval', 'My requests in approval', PurchaseRequest::where('requester_id', $u->id)->where('status', 'in_approval')->count(), '/requests'),
                $card('returned', 'Returned to me', PurchaseRequest::where('requester_id', $u->id)->where('status', 'returned')->count(), '/requests'),
                $delivery,
            ],
            'Branch Manager' => [
                $pending,
                $card('in_approval', 'Branch requests in approval', PurchaseRequest::where('branch_id', $u->branch_id)->where('status', 'in_approval')->count(), '/requests'),
                $delivery,
            ],
            'Procurement Administrator' => [
                $pending,
                $card('approved_waiting', 'Approved, waiting for an order', PurchaseRequest::where('status', 'approved')->count(), '/quotations'),
                $delivery,
                $exceptions,
            ],
            'Finance Officer' => [
                $pending,
                $card('over_budget', 'Over-budget requests in approval', PurchaseRequest::where('budget_exception', true)->where('status', 'in_approval')->count(), '/approvals'),
                $card('to_invoice', 'Orders with goods received', PurchaseOrder::whereIn('status', ['partly_received', 'received'])->count(), '/invoices/new'),
                $exceptions,
            ],
            'Head Office Manager' => [
                $pending,
                $card('in_approval', 'Requests in approval', PurchaseRequest::where('status', 'in_approval')->count(), '/requests'),
                $delivery,
                $exceptions,
            ],
            default => [
                $card('active_users', 'Active users', User::where('status', 'active')->count(), '/users'),
                $card('locked_users', 'Locked accounts', User::where('status', 'locked')->count(), '/users'),
                $card('inactive_users', 'Inactive users', User::where('status', 'inactive')->count(), '/users'),
                $card('audit_today', 'Audit entries today', AuditLog::whereDate('created_at', now()->toDateString())->count(), '/audit-log'),
            ],
        };

        return response()->json(['cards' => $cards]);
    }
}