<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::where('username', $username)->first();
    }

    private function role(string $name): int
    {
        return Role::where('name', $name)->value('id');
    }

    private function branch(string $name): int
    {
        return Branch::where('name', $name)->value('id');
    }

    private function newUser(array $over = []): array
    {
        return array_merge([
            'full_name' => 'New Person', 'username' => 'new.person', 'email' => 'new.person@asolar.test',
            'role_id' => $this->role('Requester'), 'branch_id' => $this->branch('Westlands'), 'password' => 'Nairobi2027',
        ], $over);
    }

    private function payload(User $u, array $over = []): array
    {
        return array_merge(['full_name' => $u->full_name, 'email' => $u->email, 'role_id' => $u->role_id, 'branch_id' => $u->branch_id, 'status' => $u->status], $over);
    }

    public function test_admin_creates_a_user_who_can_log_in(): void
    {
        $this->actingAs($this->user('admin'))->postJson('/api/users', $this->newUser(['username' => 'New.Person']))
            ->assertCreated()->assertJsonPath('data.username', 'new.person')->assertJsonPath('data.status', 'active');

        $log = AuditLog::where('action', 'USER_CREATED')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Nairobi2027', json_encode($log->new_values));

        $this->postJson('/api/login', ['username' => 'new.person', 'password' => 'Nairobi2027'])
            ->assertOk()->assertJsonPath('user.branch', 'Westlands');
    }

    public function test_duplicates_bad_usernames_and_weak_passwords_are_refused(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['username' => 'amina']))->assertStatus(422)->assertJsonValidationErrors(['username']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['email' => 'amina@asolar.test']))->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['username' => 'bad name']))->assertStatus(422)->assertJsonValidationErrors(['username']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['password' => 'short1']))->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['password' => 'onlyletters']))->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_head_office_roles_must_be_in_the_head_office_branch(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['role_id' => $this->role('Finance Officer'), 'branch_id' => $this->branch('Kasarani')]))
            ->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['role_id' => $this->role('Finance Officer'), 'branch_id' => $this->branch('Industrial Area')]))
            ->assertCreated();
    }

    public function test_a_branch_has_only_one_active_branch_manager(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['role_id' => $this->role('Branch Manager'), 'branch_id' => $this->branch('Kasarani')]))
            ->assertStatus(422)->assertJsonValidationErrors(['role_id']);
        $this->actingAs($admin)->postJson('/api/users', $this->newUser(['role_id' => $this->role('Branch Manager'), 'branch_id' => $this->branch('Westlands')]))
            ->assertCreated();
    }

    public function test_a_deactivated_user_cannot_log_in_until_reactivated(): void
    {
        $admin = $this->user('admin');
        $john = $this->user('john');

        $this->actingAs($admin)->putJson("/api/users/{$john->id}", $this->payload($john, ['status' => 'inactive']))
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_DEACTIVATED', 'entity_id' => $john->id]);
        $this->postJson('/api/login', ['username' => 'john', 'password' => 'rana@2026'])->assertStatus(401);

        $john->refresh();
        $this->actingAs($admin)->putJson("/api/users/{$john->id}", $this->payload($john, ['status' => 'active']))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_ACTIVATED', 'entity_id' => $john->id]);
        $this->postJson('/api/login', ['username' => 'john', 'password' => 'rana@2026'])->assertOk();
    }

    public function test_the_admin_cannot_deactivate_or_demote_themselves(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->payload($admin, ['status' => 'inactive']))
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->payload($admin, ['role_id' => $this->role('Requester')]))
            ->assertStatus(422)->assertJsonValidationErrors(['role_id']);
    }

    public function test_a_user_with_pending_approvals_cannot_be_deactivated(): void
    {
        $item = Item::where('name', 'Solar cable 6mm')->first();
        $id = $this->actingAs($this->user('amina'))->postJson('/api/requests', [
            'category_id' => $item->category_id, 'needed_by' => now()->addDays(7)->toDateString(), 'justification' => 'Needed',
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost_est' => 20000]],
        ])->json('data.id');
        $this->actingAs($this->user('amina'))->postJson("/api/requests/$id/submit")->assertOk();

        $manager = $this->user('manager.kasarani');
        $this->actingAs($this->user('admin'))->putJson("/api/users/{$manager->id}", $this->payload($manager, ['status' => 'inactive']))
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('active', $manager->fresh()->status);
    }

    public function test_password_reset_and_unlock_are_recorded(): void
    {
        $john = $this->user('john');
        User::where('id', $john->id)->update(['status' => 'locked', 'failed_logins' => 5, 'locked_until' => now()->addMinutes(15)]);
        $john->refresh();

        $this->actingAs($this->user('admin'))->putJson("/api/users/{$john->id}", $this->payload($john, ['status' => 'active', 'password' => 'NewPass2027']))
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_UNLOCKED', 'entity_id' => $john->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_PASSWORD_RESET', 'entity_id' => $john->id]);
        $this->postJson('/api/login', ['username' => 'john', 'password' => 'NewPass2027'])->assertOk();
    }

    public function test_only_the_administrator_manages_users(): void
    {
        $this->actingAs($this->user('admin'))->getJson('/api/users')->assertOk();
        $this->actingAs($this->user('finance'))->getJson('/api/users')->assertForbidden();
        $this->actingAs($this->user('headoffice'))->getJson('/api/users')->assertForbidden();
        $this->actingAs($this->user('amina'))->postJson('/api/users', $this->newUser())->assertForbidden();
    }
}