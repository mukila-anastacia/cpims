<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorItemTest extends TestCase
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
        return array_merge([
            'name' => 'Test Vendor Ltd', 'contact_person' => 'Ann', 'phone' => '0711111111',
            'email' => 'ann@test.test', 'tax_pin' => 'P123456789Z',
        ], $over);
    }

    public function test_procurement_creates_a_vendor_and_it_is_audited(): void
    {
        $this->actingAs($this->user('procurement'))->postJson('/api/vendors', $this->vendor(['tax_pin' => 'p123456789z']))
            ->assertCreated()->assertJsonPath('data.tax_pin', 'P123456789Z')->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', ['action' => 'VENDOR_CREATED']);
    }

    public function test_duplicate_or_invalid_pin_is_rejected(): void
    {
        $proc = $this->user('procurement');

        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['tax_pin' => 'P000000001A']))
            ->assertStatus(422)->assertJsonValidationErrors(['tax_pin']);
        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['tax_pin' => '12345']))
            ->assertStatus(422)->assertJsonValidationErrors(['tax_pin']);
        $this->actingAs($proc)->postJson('/api/vendors', $this->vendor(['name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_only_procurement_can_change_vendors_but_finance_can_view(): void
    {
        $this->actingAs($this->user('finance'))->getJson('/api/vendors')->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($this->user('finance'))->postJson('/api/vendors', $this->vendor())->assertForbidden();
        $this->actingAs($this->user('amina'))->getJson('/api/vendors')->assertForbidden();
        $this->actingAs($this->user('amina'))->postJson('/api/vendors', $this->vendor())->assertForbidden();
    }

    public function test_vendor_can_be_blocked_and_unblocked(): void
    {
        $proc = $this->user('procurement');
        $id = $this->actingAs($proc)->postJson('/api/vendors', $this->vendor())->json('data.id');

        $this->actingAs($proc)->putJson("/api/vendors/$id", $this->vendor(['status' => 'blocked']))
            ->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->assertDatabaseHas('audit_logs', ['action' => 'VENDOR_BLOCKED', 'entity_id' => $id]);

        $this->actingAs($proc)->putJson("/api/vendors/$id", $this->vendor(['status' => 'active']))
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'VENDOR_UNBLOCKED', 'entity_id' => $id]);
    }

    public function test_vendor_search_filters_the_list(): void
    {
        $this->actingAs($this->user('procurement'))->getJson('/api/vendors?search=SunTech')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'SunTech Distributors Ltd');
    }

    public function test_procurement_adds_and_edits_an_item(): void
    {
        $proc = $this->user('procurement');
        $category = Item::first()->category_id;

        $id = $this->actingAs($proc)->postJson('/api/items', ['name' => 'Roof clamp set', 'category_id' => $category, 'unit' => 'sets'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ITEM_CREATED']);

        $this->actingAs($proc)->putJson("/api/items/$id", ['name' => 'Roof clamp set (pair)', 'category_id' => $category, 'unit' => 'pairs'])
            ->assertOk()->assertJsonPath('data.unit', 'pairs');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ITEM_UPDATED']);
    }

    public function test_duplicate_item_name_is_rejected(): void
    {
        $existing = Item::first();

        $this->actingAs($this->user('procurement'))->postJson('/api/items', ['name' => $existing->name, 'category_id' => $existing->category_id, 'unit' => 'pcs'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_deactivated_items_leave_the_request_form_list(): void
    {
        $item = Item::where('name', 'Solar cable 6mm')->first();

        $this->actingAs($this->user('procurement'))->putJson("/api/items/{$item->id}", [
            'name' => $item->name, 'category_id' => $item->category_id, 'unit' => $item->unit, 'is_active' => false,
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'ITEM_DEACTIVATED']);

        $names = collect($this->actingAs($this->user('amina'))->getJson('/api/lookups')->json('items'))->pluck('name');
        $this->assertFalse($names->contains('Solar cable 6mm'));
        $this->assertTrue(Item::where('name', 'Solar cable 6mm')->exists()); // kept, never deleted
    }

    public function test_requesters_cannot_manage_the_catalogue(): void
    {
        $item = Item::first();

        $this->actingAs($this->user('amina'))->postJson('/api/items', ['name' => 'Anything', 'category_id' => $item->category_id, 'unit' => 'pcs'])->assertForbidden();
        $this->actingAs($this->user('amina'))->getJson('/api/items')->assertForbidden();
    }
}