<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private const LOCK_MINUTES = 15;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::with(['role', 'branch'])->where('username', $data['username'])->first();

        // A locked account stays locked until the lock time passes (UAM-04)
        if ($user && $user->status === 'locked') {
            if ($user->locked_until && $user->locked_until->isFuture()) {
                AuditService::log('LOGIN_BLOCKED', 'user', $user->id, null, null, $user->id);
                return response()->json([
                    'message' => 'Account locked. Try again after ' . $user->locked_until->format('H:i') . '.',
                ], 423);
            }
            $user->update(['status' => 'active', 'failed_logins' => 0, 'locked_until' => null]);
        }

        if (! $user || $user->status !== 'active' || ! Hash::check($data['password'], $user->password)) {
            $this->recordFailure($user, $data['username']);
            return response()->json(['message' => 'Invalid username or password.'], 401);
        }

        $user->update(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()]);
        Auth::login($user);
        $request->session()->regenerate();
        AuditService::log('LOGIN', 'user', $user->id, null, null, $user->id);

        return response()->json(['user' => $this->payload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditService::log('LOGOUT', 'user', $request->user()->id);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->payload($request->user()->load(['role', 'branch']))]);
    }

    private function recordFailure(?User $user, string $username): void
    {
        if (! $user) {
            AuditService::log('LOGIN_FAILED', 'user', null, null, ['username' => $username]);
            return;
        }

        AuditService::log('LOGIN_FAILED', 'user', $user->id, null, null, $user->id);
        if ($user->status !== 'active') {
            return;
        }

        $attempts = $user->failed_logins + 1;
        $limit = (int) Setting::get('lockout_attempts', 5);

        if ($attempts >= $limit) {
            $user->update([
                'failed_logins' => $attempts,
                'status' => 'locked',
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
            ]);
            AuditService::log('ACCOUNT_LOCKED', 'user', $user->id, null, null, $user->id);
        } else {
            $user->update(['failed_logins' => $attempts]);
        }
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'username' => $user->username,
            'role' => $user->role->name,
            'branch' => $user->branch->name,
            'branch_id' => $user->branch_id,
            'is_head_office' => $user->isHeadOffice(),
        ];
    }
}
