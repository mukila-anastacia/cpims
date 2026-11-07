<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $stamp = fn (array $rows) => array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $rows);
        $role = fn (string $n) => DB::table('roles')->where('name', $n)->value('id');
        $branch = fn (string $n) => DB::table('branches')->where('name', $n)->value('id');
        $category = fn (string $n) => DB::table('categories')->where('name', $n)->value('id');

        // Test users. All use the password: Password@123  (change it before real use)
        $pw = Hash::make('Password@123');
        $users = [
            ['System Administrator', 'Industrial Area', 'Grace Wanjiru', 'admin'],
            ['Procurement Administrator', 'Industrial Area', 'David Otieno', 'procurement'],
            ['Finance Officer', 'Industrial Area', 'Mary Achieng', 'finance'],
            ['Head Office Manager', 'Industrial Area', 'James Kamau', 'headoffice'],
            ['Branch Manager', 'Kasarani', 'Peter Mwangi', 'manager.kasarani'],
            ['Requester', 'Kasarani', 'Amina Wafula', 'amina'],
            ['Branch Manager', 'Karen', 'Susan Njeri', 'manager.karen'],
            ['Requester', 'Karen', 'John Kiprop', 'john'],
        ];
        foreach ($users as [$r, $b, $name, $username]) {
            DB::table('users')->insert([
                'branch_id' => $branch($b), 'role_id' => $role($r), 'full_name' => $name,
                'username' => $username, 'email' => $username . '@asolar.test', 'password' => $pw,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('items')->insert($stamp([
            ['category_id' => $category('Solar panels'), 'name' => 'Mono solar panel 450W', 'unit' => 'pcs'],
            ['category_id' => $category('Solar panels'), 'name' => 'Mono solar panel 550W', 'unit' => 'pcs'],
            ['category_id' => $category('Batteries'), 'name' => 'Lithium battery 200Ah', 'unit' => 'pcs'],
            ['category_id' => $category('Batteries'), 'name' => 'Gel battery 100Ah', 'unit' => 'pcs'],
            ['category_id' => $category('Inverters and controllers'), 'name' => 'Hybrid inverter 5kW', 'unit' => 'pcs'],
            ['category_id' => $category('Inverters and controllers'), 'name' => 'Charge controller 60A', 'unit' => 'pcs'],
            ['category_id' => $category('Cables and accessories'), 'name' => 'Solar cable 6mm', 'unit' => 'm'],
            ['category_id' => $category('Cables and accessories'), 'name' => 'Battery cable 35mm', 'unit' => 'm'],
            ['category_id' => $category('Cables and accessories'), 'name' => 'MC4 connector pair', 'unit' => 'pairs'],
            ['category_id' => $category('Installation materials'), 'name' => 'Aluminium mounting rail 4.2m', 'unit' => 'pcs'],
        ]));

        // Sample vendors with made-up details
        DB::table('vendors')->insert($stamp([
            ['name' => 'SunTech Distributors Ltd', 'contact_person' => 'Brian Odhiambo', 'phone' => '0700000001', 'email' => 'sales@suntech.test', 'tax_pin' => 'P000000001A'],
            ['name' => 'PowerGrid Supplies', 'contact_person' => 'Faith Muthoni', 'phone' => '0700000002', 'email' => 'info@powergrid.test', 'tax_pin' => 'P000000002B'],
            ['name' => 'Kenya Solar Wholesalers', 'contact_person' => 'Hassan Ali', 'phone' => '0700000003', 'email' => 'orders@kenyasolar.test', 'tax_pin' => 'P000000003C'],
            ['name' => 'VoltEdge Electricals', 'contact_person' => 'Lucy Chebet', 'phone' => '0700000004', 'email' => 'quotes@voltedge.test', 'tax_pin' => 'P000000004D'],
        ]));

        // Budgets: KES 500,000 for every branch and category, Oct to Dec 2026 (sample values)
        $rows = [];
        foreach (DB::table('branches')->pluck('id') as $b) {
            foreach (DB::table('categories')->pluck('id') as $c) {
                $rows[] = ['branch_id' => $b, 'category_id' => $c, 'period_start' => '2026-10-01', 'period_end' => '2026-12-31', 'amount' => 500000];
            }
        }
        DB::table('budgets')->insert($stamp($rows));
    }
}
