<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $stamp = fn (array $rows) => array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $rows);

        DB::table('roles')->insert($stamp([
            ['name' => 'Requester', 'description' => 'Branch staff who raise requests and record deliveries'],
            ['name' => 'Branch Manager', 'description' => 'First-level approver for the branch'],
            ['name' => 'Procurement Administrator', 'description' => 'Manages vendors, quotations and purchase orders'],
            ['name' => 'Finance Officer', 'description' => 'Manages budgets and matches invoices'],
            ['name' => 'Head Office Manager', 'description' => 'High-value approvals and oversight'],
            ['name' => 'System Administrator', 'description' => 'Manages users, roles and system settings'],
        ]));

        DB::table('branches')->insert($stamp([
            ['name' => 'Industrial Area', 'location' => 'Nairobi', 'is_head_office' => true],
            ['name' => 'Westlands', 'location' => 'Nairobi', 'is_head_office' => false],
            ['name' => 'Kasarani', 'location' => 'Nairobi', 'is_head_office' => false],
            ['name' => 'Karen', 'location' => 'Nairobi', 'is_head_office' => false],
            ['name' => 'Embakasi', 'location' => 'Nairobi', 'is_head_office' => false],
            ['name' => 'Kisumu', 'location' => 'Kisumu', 'is_head_office' => false],
            ['name' => 'Nakuru', 'location' => 'Nakuru', 'is_head_office' => false],
            ['name' => 'Mombasa', 'location' => 'Mombasa', 'is_head_office' => false],
            ['name' => 'Machakos', 'location' => 'Machakos', 'is_head_office' => false],
        ]));

        DB::table('categories')->insert($stamp([
            ['name' => 'Solar panels'],
            ['name' => 'Batteries'],
            ['name' => 'Inverters and controllers'],
            ['name' => 'Cables and accessories'],
            ['name' => 'Installation materials'],
        ]));

        $role = fn (string $n) => DB::table('roles')->where('name', $n)->value('id');
        // Starting approval limits (BR-01). Confirm the real values with A-Solar and change them here.
        DB::table('approval_rules')->insert($stamp([
            ['level' => 1, 'role_id' => $role('Branch Manager'), 'min_value' => 0, 'max_value' => 50000],
            ['level' => 2, 'role_id' => $role('Procurement Administrator'), 'min_value' => 50000, 'max_value' => 200000],
            ['level' => 3, 'role_id' => $role('Finance Officer'), 'min_value' => 200000, 'max_value' => null],
        ]));

        DB::table('settings')->insert($stamp([
            ['name' => 'duplicate_window_days', 'value' => '14', 'description' => 'Days used to detect duplicate purchases (BR-04)'],
            ['name' => 'price_tolerance_pct', 'value' => '2', 'description' => 'Invoice price tolerance in percent (BR-05)'],
            ['name' => 'lockout_attempts', 'value' => '5', 'description' => 'Failed logins before an account locks'],
            ['name' => 'idle_timeout_minutes', 'value' => '15', 'description' => 'Minutes of inactivity before logout'],
            ['name' => 'min_quotations', 'value' => '3', 'description' => 'Quotations required above the threshold (BR-06)'],
            ['name' => 'quotation_threshold', 'value' => '100000', 'description' => 'Value above which several quotations are required (KES)'],
            ['name' => 'reminder_days', 'value' => '2', 'description' => 'Working days before an approver is reminded'],
        ]));
    }
}