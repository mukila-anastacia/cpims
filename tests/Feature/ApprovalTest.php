<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalTest extends TestCase
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

    private function payload(string $itemName, float $cost): array
    {
        $item = Item::where('name', $itemName)->first();

        return [
            'category_id' => $item->category_id,
            'needed_by' => now()->addDays(7)->toDateString(),
            'justification' => 'Needed for a customer installation',
            'lines' => [['item_id' => $item->id, 'quantity' => 1, 'unit_cost_est' => $cost]],
        ];
    }

    /** Raise and submit a request as a requester; returns its id. */
    private function submitted(string $requester, string $item, float $cost): int
    {
        $user = $this->user($requester);
        $id = $this->actingAs($user)->postJson('/api/requests', $this->payload($item, $cost))->json('data.id');
        $this->actingAs($user)->postJson("/api/requests/$id/submit")->assertOk();

        return $id;
    }

    private function decide(string $username, int $id, string $decision, ?string $comment = null)
    {
        return $this->actingAs($this->user($username))->postJson("/api/requests/$id/decision", ['decision' => $decision, 'comment' => $comment]);
    }

    public function test_approver_sees_only_their_pending_requests(): void
    {
        $this->submitted('amina', 'Gel battery 100Ah', 120000); // two levels: manager, then procurement

        $this->actingAs($this->user('manager.kasarani'))->getJson('/api/approvals')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user('procurement'))->getJson('/api/approvals')->assertOk()->assertJsonCount(0, 'data'); // not their level yet
        $this->actingAs($this->user('manager.karen'))->getJson('/api/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user('amina'))->getJson('/api/approvals')->assertForbidden();
    }

    public function test_multi_level_approval_moves_through_levels_to_approved(): void
    {
        $id = $this->submitted('amina', 'Gel battery 100Ah', 120000);

        $this->decide('manager.kasarani', $id, 'approve')->assertOk()
            ->assertJsonPath('data.status', 'in_approval')->assertJsonPath('data.current_level', 2);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->user('procurement')->id]);

        $this->decide('procurement', $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(AppNotification::where('user_id', $this->user('amina')->id)->where('message', 'like', '%approved%')->exists());
    }

    public function test_wrong_user_cannot_decide(): void
    {
        $id = $this->submitted('amina', 'Gel battery 100Ah', 120000);

        $this->decide('finance', $id, 'approve')->assertForbidden();
        $this->decide('manager.karen', $id, 'approve')->assertForbidden();
        $this->decide('procurement', $id, 'approve')->assertForbidden(); // right role, but not their level yet
        $this->assertSame('in_approval', PurchaseRequest::find($id)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACCESS_DENIED']);
    }

    public function test_reject_needs_a_comment_and_notifies_the_requester(): void
    {
        $id = $this->submitted('amina', 'Solar cable 6mm', 20000);

        $this->decide('manager.kasarani', $id, 'reject')->assertStatus(422)->assertJsonValidationErrors(['comment']);
        $this->decide('manager.kasarani', $id, 'reject', 'Use existing stock first')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertTrue(AppNotification::where('user_id', $this->user('amina')->id)->where('message', 'like', '%rejected%')->exists());
    }

    public function test_returned_request_can_be_edited_and_resubmitted(): void
    {
        $id = $this->submitted('amina', 'Solar cable 6mm', 20000);
        $this->decide('manager.kasarani', $id, 'return', 'Please add the supplier price')->assertOk()->assertJsonPath('data.status', 'returned');

        $amina = $this->user('amina');
        $this->actingAs($amina)->putJson("/api/requests/$id", $this->payload('Solar cable 6mm', 21000))->assertOk();
        $this->actingAs($amina)->postJson("/api/requests/$id/submit")->assertOk()->assertJsonPath('data.status', 'in_approval');

        $this->assertSame(1, Approval::where('purchase_request_id', $id)->where('decision', 'pending')->count());
        $this->assertSame(1, Approval::where('purchase_request_id', $id)->where('decision', 'returned')->count()); // history kept
    }

    public function test_nobody_can_decide_on_their_own_request(): void
    {
        $id = $this->submitted('amina', 'Solar cable 6mm', 20000);
        $manager = $this->user('manager.kasarani');
        PurchaseRequest::find($id)->update(['requester_id' => $manager->id]);

        $this->decide('manager.kasarani', $id, 'approve')->assertForbidden();
    }

    public function test_decision_is_audited_with_the_comment(): void
    {
        $id = $this->submitted('amina', 'Solar cable 6mm', 20000);
        $this->decide('manager.kasarani', $id, 'approve', 'Looks fine')->assertOk();

        $log = AuditLog::where('action', 'REQUEST_APPROVED')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Looks fine', $log->new_values['comment']);
        $this->assertSame($this->user('manager.kasarani')->id, $log->user_id);
    }

    public function test_cannot_decide_twice(): void
    {
        $id = $this->submitted('amina', 'Solar cable 6mm', 20000); // single level
        $this->decide('manager.kasarani', $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->decide('manager.kasarani', $id, 'approve')->assertStatus(409);
    }
}