<?php

namespace App\Http\Controllers;

use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->service->pendingFor($request->user())->map(function ($a) {
            $r = $a->purchaseRequest;

            return [
                'request_id' => $r->id,
                'request_no' => $r->request_no,
                'branch' => $r->branch->name,
                'requester' => $r->requester->full_name,
                'category' => $r->category->name,
                'total_estimate' => (float) $r->total_estimate,
                'level' => $a->level,
                'duplicate_flag' => (bool) $r->duplicate_flag,
                'budget_exception' => (bool) $r->budget_exception,
                'needed_by' => $r->needed_by?->format('Y-m-d'),
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject,return'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $r = $this->service->decide($request->user(), $id, $data['decision'], $data['comment'] ?? null);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'current_level' => (int) $r->current_level]]);
    }
}