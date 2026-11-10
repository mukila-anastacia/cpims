<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Approval;
use App\Models\PurchaseRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    /** Requests waiting for this user right now: their level is the current one (PRA-04). */
    public function pendingFor(User $user)
    {
        return Approval::with(['purchaseRequest.branch', 'purchaseRequest.requester', 'purchaseRequest.category'])
            ->where('approver_id', $user->id)
            ->where('decision', 'pending')
            ->whereHas('purchaseRequest', fn ($q) => $q
                ->where('status', 'in_approval')
                ->whereColumn('purchase_requests.current_level', 'approvals.level'))
            ->orderBy('id')
            ->get();
    }

    /** Approve, reject or return a request (PRA-05, PRA-06, BR-02). */
    public function decide(User $user, int $requestId, string $decision, ?string $comment): PurchaseRequest
    {
        $comment = trim((string) $comment);
        if ($decision !== 'approve' && $comment === '') {
            throw ValidationException::withMessages(['comment' => 'Enter a comment explaining your decision.']);
        }

        // Permission checks come first, outside the transaction, so a refusal is still written to the audit log
        $request = PurchaseRequest::findOrFail($requestId);

        if ($request->requester_id === $user->id) {
            AuditService::log('ACCESS_DENIED', 'purchase_request', $request->id, null, ['reason' => 'own request']);
            abort(403, 'You cannot decide on your own request.');
        }
        if ($request->status !== 'in_approval') {
            abort(409, 'This request is not waiting for approval.');
        }
        $mine = Approval::where('purchase_request_id', $request->id)->where('level', $request->current_level)
            ->where('approver_id', $user->id)->where('decision', 'pending')->exists();
        if (! $mine) {
            AuditService::log('ACCESS_DENIED', 'purchase_request', $request->id, null, ['reason' => 'not the current approver']);
            abort(403, 'This request is not waiting for your approval.');
        }

        return DB::transaction(function () use ($user, $requestId, $decision, $comment) {
            $request = PurchaseRequest::with(['requester', 'branch'])->lockForUpdate()->findOrFail($requestId);
            $approval = Approval::where('purchase_request_id', $request->id)->where('level', $request->current_level)
                ->where('approver_id', $user->id)->where('decision', 'pending')->first();

            if ($request->status !== 'in_approval' || ! $approval) {
                abort(409, 'This request has already been decided.');
            }

            $decisionValue = ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$decision];
            $approval->update(['decision' => $decisionValue, 'comment' => $comment ?: null, 'decided_at' => now()]);

            $newStatus = 'in_approval';
            $newLevel = $request->current_level;

            if ($decision === 'approve') {
                $next = Approval::where('purchase_request_id', $request->id)->where('decision', 'pending')
                    ->where('level', '>', $request->current_level)->orderBy('level')->first();

                if ($next) {
                    $newLevel = $next->level;
                    $request->update(['current_level' => $newLevel]);
                    $this->notify($next->approver_id, "Request {$request->request_no} from {$request->requester->full_name} ({$request->branch->name}) needs your approval.", $request->id);
                } else {
                    $newStatus = 'approved';
                    $request->update(['status' => 'approved']);
                    $this->notify($request->requester_id, "Your request {$request->request_no} was approved.", $request->id);
                    $procurementRole = Role::where('name', 'Procurement Administrator')->value('id');
                    foreach (User::where('role_id', $procurementRole)->where('status', 'active')->pluck('id') as $uid) {
                        $this->notify($uid, "Request {$request->request_no} is approved and ready for quotations.", $request->id);
                    }
                }
            } elseif ($decision === 'reject') {
                $newStatus = 'rejected';
                $request->update(['status' => 'rejected']);
                $this->notify($request->requester_id, "Your request {$request->request_no} was rejected: {$comment}", $request->id);
            } else {
                $newStatus = 'returned';
                $request->update(['status' => 'returned']);
                $this->notify($request->requester_id, "Your request {$request->request_no} was returned for changes: {$comment}", $request->id);
            }

            AuditService::log(
                'REQUEST_' . strtoupper($decisionValue),
                'purchase_request',
                $request->id,
                ['status' => 'in_approval', 'level' => $approval->level],
                ['status' => $newStatus, 'level' => $newLevel, 'comment' => $comment ?: null]
            );

            return $request->fresh();
        });
    }

    private function notify(int $userId, string $message, int $requestId): void
    {
        AppNotification::create(['user_id' => $userId, 'message' => $message, 'link' => "/requests/{$requestId}"]);
    }
}