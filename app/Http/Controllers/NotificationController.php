<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AppNotification::where('user_id', $request->user()->id);

        return response()->json([
            'unread' => (clone $q)->where('is_read', false)->count(),
            'data' => $q->latest('id')->limit(15)->get(['id', 'message', 'link', 'is_read', 'created_at']),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }
}