<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function args(array $over = []): array
    {
        return array_merge(['--name' => 'Real Admin', '--username' => 'real.admin', '--email' => 'real.admin@asolar.test', '--password' => 'Strong2027pass'], $over);
    }

    public function test_it_creates_a_head_office_administrator(): void
    {
        $this->artisan('cpims:create-admin', $this->args())->assertExitCode(0);

        $user = User::where('username', 'real.admin')->first();
        $this->assertSame('System Administrator', $user->role->name);
        $this->assertTrue((bool) $user->branch->is_head_office);
        $this->assertNotSame('Strong2027pass', $user->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_CREATED', 'entity_id' => $user->id]);
        $this->postJson('/api/login', ['username' => 'real.admin', 'password' => 'Strong2027pass'])->assertOk();
    }

    public function test_it_refuses_weak_passwords_and_duplicates(): void
    {
        $this->artisan('cpims:create-admin', $this->args(['--password' => 'weak']))->assertExitCode(1);
        $this->assertSame(0, User::count());

        $this->artisan('cpims:create-admin', $this->args())->assertExitCode(0);
        $this->artisan('cpims:create-admin', $this->args())->assertExitCode(1);
        $this->assertSame(1, User::count());
    }
}