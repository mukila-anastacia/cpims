<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'cpims:create-admin {--name=} {--username=} {--email=} {--password=}';

    protected $description = 'Create the first System Administrator account for a new installation';

    public function handle(): int
    {
        $role = Role::where('name', 'System Administrator')->first();
        $branch = Branch::where('is_head_office', true)->first();

        if (! $role || ! $branch) {
            $this->error('The roles and the head office branch are missing. Run: php artisan db:seed --class=ReferenceDataSeeder');

            return self::FAILURE;
        }

        $data = [
            'full_name' => $this->option('name') ?: $this->ask('Full name'),
            'username' => strtolower((string) ($this->option('username') ?: $this->ask('Username (letters, numbers, dots)'))),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Password (at least 8 characters, with letters and numbers)'),
        ];

        $check = Validator::make($data, [
            'full_name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,40}$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create($data + ['role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active']);
        AuditService::log('USER_CREATED', 'user', $user->id, null, ['username' => $user->username, 'role' => $role->name, 'branch' => $branch->name]);

        $this->info("Administrator {$user->username} created. They can log in now.");

        return self::SUCCESS;
    }
}