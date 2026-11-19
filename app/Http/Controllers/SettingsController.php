<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRule;
use App\Models\Role;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const LEVEL_ROLES = ['Procurement Administrator', 'Finance Officer', 'Head Office Manager'];

    private const EDITABLE = [
        'duplicate_window_days' => [
            'label' => 'Duplicate window (days)', 'default' => 14,
            'help' => 'A request is flagged when another branch asked for the same item within this many days (BR-04).',
            'rules' => ['required', 'integer', 'between:1,90'],
        ],
        'price_tolerance_pct' => [
            'label' => 'Invoice price tolerance (%)', 'default' => 2,
            'help' => 'An invoice price may differ from the order price by this much before it becomes an exception (BR-05).',
            'rules' => ['required', 'numeric', 'between:0,20'],
        ],
        'min_quotations' => [
            'label' => 'Minimum quotations', 'default' => 3,
            'help' => 'How many quotations are needed above the threshold below (BR-06).',
            'rules' => ['required', 'integer', 'between:1,10'],
        ],
        'quotation_threshold' => [
            'label' => 'Quotation threshold (KES)', 'default' => 100000,
            'help' => 'Requests above this value need the minimum number of quotations.',
            'rules' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ],
        'lockout_attempts' => [
            'label' => 'Failed logins before lock-out', 'default' => 5,
            'help' => 'An account locks for 15 minutes after this many wrong passwords.',
            'rules' => ['required', 'integer', 'between:3,10'],
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(collect(self::EDITABLE)->map(fn ($m) => $m['rules'])->all());

        $old = [];
        $new = [];
        foreach (self::EDITABLE as $key => $meta) {
            $before = (float) Setting::get($key, $meta['default']);
            $after = (float) $data[$key];
            if (abs($before - $after) < 0.00001) {
                continue;
            }
            $old[$key] = $before;
            $new[$key] = $after;
            Setting::updateOrCreate(['name' => $key], ['value' => rtrim(rtrim(number_format($after, 2, '.', ''), '0'), '.')]);
        }

        if ($new) {
            AuditService::log('SETTINGS_UPDATED', 'settings', null, $old, $new);
        }

        return response()->json($this->payload($request));
    }

    /** Approval levels: Level 1 is always the Branch Manager, Levels 2 and 3 have a role and a starting amount (BR-01). */
    public function updateApproval(Request $request): JsonResponse
    {
        $roleIds = Role::whereIn('name', self::LEVEL_ROLES)->pluck('id')->all();

        $d = $request->validate([
            'level2_min' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'level2_role_id' => ['required', Rule::in($roleIds)],
            'level3_enabled' => ['required', 'boolean'],
            'level3_min' => ['nullable', 'numeric', 'max:1000000000'],
            'level3_role_id' => ['nullable', Rule::in($roleIds)],
        ]);

        $three = (bool) $d['level3_enabled'];
        if ($three) {
            $errors = [];
            if (! isset($d['level3_min'])) {
                $errors['level3_min'] = 'Enter the amount where level 3 starts.';
            } elseif ((float) $d['level3_min'] <= (float) $d['level2_min']) {
                $errors['level3_min'] = 'Level 3 must start above the amount for level 2.';
            }
            if (empty($d['level3_role_id'])) {
                $errors['level3_role_id'] = 'Choose a role for level 3.';
            } elseif ((int) $d['level3_role_id'] === (int) $d['level2_role_id']) {
                $errors['level3_role_id'] = 'Levels 2 and 3 need different roles.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        $old = $this->approval();

        DB::transaction(function () use ($d, $three) {
            $branchManager = Role::where('name', 'Branch Manager')->value('id');

            $l1 = ApprovalRule::firstOrNew(['level' => 1]);
            $l1->fill(['role_id' => $branchManager, 'min_value' => 0, 'max_value' => $d['level2_min'], 'is_active' => true])->save();

            $l2 = ApprovalRule::firstOrNew(['level' => 2]);
            $l2->fill(['role_id' => $d['level2_role_id'], 'min_value' => $d['level2_min'], 'max_value' => $three ? $d['level3_min'] : null, 'is_active' => true])->save();

            $l3 = ApprovalRule::firstOrNew(['level' => 3]);
            $l3->fill([
                'role_id' => $three ? $d['level3_role_id'] : ($l3->role_id ?: $d['level2_role_id']),
                'min_value' => $three ? $d['level3_min'] : ($l3->min_value ?: 200000),
                'max_value' => null,
                'is_active' => $three,
            ])->save();
        });

        $names = Role::pluck('name', 'id');
        $label = fn (array $a) => [
            'level2_min' => $a['level2_min'], 'level2_role' => $names[$a['level2_role_id']] ?? null,
            'level3_enabled' => $a['level3_enabled'], 'level3_min' => $a['level3_enabled'] ? $a['level3_min'] : null, 'level3_role' => $a['level3_enabled'] ? ($names[$a['level3_role_id']] ?? null) : null,
        ];
        AuditService::log('APPROVAL_LIMITS_UPDATED', 'approval_rules', null, $label($old), $label($this->approval()));

        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $settings = [];
        foreach (self::EDITABLE as $key => $meta) {
            $settings[] = ['key' => $key, 'label' => $meta['label'], 'help' => $meta['help'], 'value' => (float) Setting::get($key, $meta['default'])];
        }

        return [
            'settings' => $settings,
            'approval' => $this->approval(),
            'roles' => Role::whereIn('name', self::LEVEL_ROLES)->orderBy('id')->get(['id', 'name']),
            'can_edit' => $request->user()->hasRole('System Administrator'),
        ];
    }

    private function approval(): array
    {
        $rules = ApprovalRule::orderBy('level')->get()->keyBy('level');
        $l2 = $rules->get(2);
        $l3 = $rules->get(3);

        return [
            'level2_min' => (float) ($l2?->min_value ?? 50000),
            'level2_role_id' => $l2?->role_id,
            'level3_enabled' => (bool) ($l3?->is_active ?? false),
            'level3_min' => (float) ($l3?->min_value ?? 200000),
            'level3_role_id' => $l3?->role_id,
        ];
    }
}