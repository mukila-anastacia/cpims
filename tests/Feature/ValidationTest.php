<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationTest extends TestCase
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

    private function vendor(array $over = []): array
    {
        return array_merge(['name' => 'Phone Test Ltd', 'tax_pin' => 'P555555555Q', 'phone' => '0712345678'], $over);
    }

    public function test_request_lines_need_a_cost_above_zero(): void
    {
        $item = Item::first();
        $body = fn ($cost) => [
            'category_id' => $item->category_id,
            'justification' => 'Needed',
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost_est' => $cost]],
        ];
        $amina = $this->user('amina');

        $this->actingAs($amina)->postJson('/api/requests', $body(0))->assertStatus(422)->assertJsonValidationErrors(['lines.0.unit_cost_est']);
        $this->actingAs($amina)->postJson('/api/requests', $body(null))->assertStatus(422)->assertJsonValidationErrors(['lines.0.unit_cost_est']);
        $this->actingAs($amina)->postJson('/api/requests', $body(1500))->assertCreated();
    }

    public function test_vendor_phone_must_be_a_kenyan_number(): void
    {
        $proc = $this->user('procurement');

        foreach (['12345', 'abcdefghij', '0812345678', '071234567'] as $bad) {
            $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['phone' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['phone']);
        }
    }

    public function test_vendor_phone_is_cleaned_before_saving(): void
    {
        $proc = $this->user('procurement');

        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['phone' => '0712 345-678']))
            ->assertCreated()->assertJsonPath('data.phone', '0712345678');
        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['tax_pin' => 'P666666666R', 'phone' => '+254 712 345 678']))
            ->assertCreated()->assertJsonPath('data.phone', '+254712345678');
        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['tax_pin' => 'P777777777S', 'phone' => '']))
            ->assertCreated()->assertJsonPath('data.phone', null);
    }

    public function test_helper_routes_are_gone(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/csrf')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/admin/ping')->assertNotFound();
    }

    public function test_the_app_shell_loads(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('id="root"', false);
    }
}