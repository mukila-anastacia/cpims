<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /** These roles work from head office, so they must belong to the head office branch. */
    private const HEAD_ROLES = ['Procurement Administrator', 'Finance Officer', 'Head Office Manager', 'System Administrator'];

    public function index(Request $request): JsonResponse
    {
        $q = User::with(['role', 'branch'])->orderBy('full_name');

        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('full_name', 'like', "%$s%")->orWhere('username', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($request->filled('role_id')) {
            $q->where('role_id', $request->query('role_id'));
        }
        if (in_array($request->query('status'), ['active', 'inactive', 'locked'], true)) {
            $q->where('status', $request->query('status'));
        }

        return response()->json([
            'data' => $q->limit(300)->get()->map(fn ($u) => $this->present($u, $request->user()))->values(),
            'roles' => Role::orderBy('id')->get(['id', 'name']),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'name', 'is_head_office']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,40}$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'role_id' => ['required', 'exists:roles,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ], [
            'username.regex' => 'Use 3 to 40 letters, numbers, dots, dashes or underscores, with no spaces.',
        ]);

        $this->guardRoleBranch($data, 'active');

        $u = User::create($data + ['status' => 'active'])->load(['role', 'branch']);
        AuditService::log('USER_CREATED', 'user', $u->id, null, $this->snapshot($u));

        return response()->json(['data' => $this->present($u, $request->user())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $u = User::with(['role', 'branch'])->findOrFail($id);
        $old = $this->snapshot($u);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($u->id)],
            'role_id' => ['required', 'exists:roles,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'status' => ['required', Rule::in($u->status === 'locked' ? ['active', 'inactive', 'locked'] : ['active', 'inactive'])],
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
        ]);

        if ($u->id === $request->user()->id) {
            if ($data['status'] !== 'active') {
                throw ValidationException::withMessages(['status' => 'You cannot deactivate your own account.']);
            }
            if ((int) $data['role_id'] !== (int) $u->role_id) {
                throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
            }
        }

        $this->guardRoleBranch($data, $data['status'], $u->id);

        $leaving = $data['status'] === 'inactive' || (int) $data['role_id'] !== (int) $u->role_id;
        if ($leaving && $this->hasPendingApprovals($u)) {
            throw ValidationException::withMessages(['status' => 'This user has requests waiting for their approval. Wait until they are decided before deactivating or changing the role.']);
        }

        $fill = collect($data)->except('password')->all();
        if ($data['status'] === 'active') {
            $fill += ['failed_logins' => 0, 'locked_until' => null];
        }
        $passwordChanged = ! empty($data['password']);
        if ($passwordChanged) {
            $fill['password'] = $data['password'];
        }

        $u->update($fill);
        $u->refresh()->load(['role', 'branch']);

        $action = 'USER_UPDATED';
        if ($old['status'] !== $u->status) {
            $action = match (true) {
                $u->status === 'inactive' => 'USER_DEACTIVATED',
                $old['status'] === 'inactive' => 'USER_ACTIVATED',
                $old['status'] === 'locked' => 'USER_UNLOCKED',
                default => 'USER_UPDATED',
            };
        }
        AuditService::log($action, 'user', $u->id, $old, $this->snapshot($u));
        if ($passwordChanged) {
            AuditService::log('USER_PASSWORD_RESET', 'user', $u->id);
        }

        return response()->json(['data' => $this->present($u, $request->user())]);
    }

    private function guardRoleBranch(array $data, string $status, ?int $ignoreId = null): void
    {
        $role = Role::find($data['role_id']);
        $branch = Branch::find($data['branch_id']);

        if (in_array($role->name, self::HEAD_ROLES, true) && ! $branch->is_head_office) {
            throw ValidationException::withMessages(['branch_id' => "{$role->name} must be assigned to the head office branch."]);
        }

        if ($role->name === 'Branch Manager' && $status !== 'inactive') {
            $clash = User::where('role_id', $role->id)->where('branch_id', $branch->id)->where('status', '!=', 'inactive')
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['role_id' => "{$branch->name} already has an active Branch Manager."]);
            }
        }
    }

    private function hasPendingApprovals(User $u): bool
    {
        return Approval::where('approver_id', $u->id)->where('decision', 'pending')
            ->whereHas('purchaseRequest', fn ($q) => $q->where('status', 'in_approval'))->exists();
    }

    private function snapshot(User $u): array
    {
        return ['full_name' => $u->full_name, 'email' => $u->email, 'role' => $u->role->name, 'branch' => $u->branch->name, 'status' => $u->status];
    }

    private function present(User $u, User $viewer): array
    {
        return [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'username' => $u->username,
            'email' => $u->email,
            'role_id' => $u->role_id,
            'role' => $u->role->name,
            'branch_id' => $u->branch_id,
            'branch' => $u->branch->name,
            'status' => $u->status,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'is_self' => $u->id === $viewer->id,
        ];
    }
}