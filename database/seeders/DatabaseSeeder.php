<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // Demo users, vendors and budgets exist for development and testing only
        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}