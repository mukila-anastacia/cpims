<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AddStoreUsersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $roleId = DB::table('roles')->where('name', 'Requester')->value('id');

        foreach ([['Kasarani', 'Faith Njoroge', 'store.kasarani'], ['Karen', 'Brian Mutua', 'store.karen']] as [$branch, $name, $username]) {
            if (DB::table('users')->where('username', $username)->exists()) {
                continue;
            }
            DB::table('users')->insert([
                'branch_id' => DB::table('branches')->where('name', $branch)->value('id'),
                'role_id' => $roleId, 'full_name' => $name, 'username' => $username,
                'email' => $username . '@asolar.test', 'password' => Hash::make('rana@2026'),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}