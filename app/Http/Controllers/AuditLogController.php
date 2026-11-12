<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $q = AuditLog::with('user')->latest('id');

        if ($request->filled('action')) {
            $q->where('action', $request->query('action'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('entity')) {
            $q->where('entity', $request->query('entity'));
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }

        $page = $q->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($l) => [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'user' => $l->user?->full_name ?? 'System',
                'username' => $l->user?->username,
                'action' => $l->action,
                'entity' => $l->entity,
                'entity_id' => $l->entity_id,
                'ip' => $l->ip_address,
                'old' => $l->old_values,
                'new' => $l->new_values,
            ])->values(),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'total' => $page->total(),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }
}