<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const USERS = ['R' => 'amina', 'BM' => 'manager.kasarani', 'PA' => 'procurement', 'FO' => 'finance', 'HO' => 'headoffice', 'SA' => 'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Every API route with the roles that may use it. 999999 is an id that does not exist. */
    private static function routes(): array
    {
        $all = ['R', 'BM', 'PA', 'FO', 'HO', 'SA'];
        $approvers = ['BM', 'PA', 'FO', 'HO'];
        $head = ['PA', 'FO', 'HO'];
        $orders = ['R', 'BM', 'PA', 'FO', 'HO'];

        return [
            ['GET', '/api/me', $all],
            ['POST', '/api/me/password', $all],
            ['GET', '/api/settings', ['SA', 'HO']],
            ['PUT', '/api/settings', ['SA']],
            ['PUT', '/api/approval-rules', ['SA']],
            ['GET', '/api/dashboard', $all],
            ['GET', '/api/notifications', $all],
            ['POST', '/api/notifications/read', $all],
            ['GET', '/api/lookups', $all],
            ['GET', '/api/budget/remaining', $all],
            ['GET', '/api/requests', $all],
            ['GET', '/api/requests/999999', $all],
            ['GET', '/api/requests/999999/checks', $all],

            ['POST', '/api/requests', ['R']],
            ['PUT', '/api/requests/999999', ['R']],
            ['POST', '/api/requests/999999/submit', ['R']],
            ['POST', '/api/requests/999999/cancel', ['R']],

            ['GET', '/api/approvals', $approvers],
            ['POST', '/api/requests/999999/decision', $approvers],
            ['GET', '/api/reports/summary', $approvers],
            ['GET', '/api/reports/export', $approvers],

            ['GET', '/api/vendors', $head],
            ['GET', '/api/items', $head],
            ['GET', '/api/quotation-queue', $head],
            ['GET', '/api/requests/999999/quotations', $head],
            ['GET', '/api/invoices', $head],
            ['GET', '/api/invoices/999999', $head],

            ['GET', '/api/orders', $orders],
            ['GET', '/api/orders/999999', $orders],
            ['POST', '/api/orders/999999/receipts', ['R', 'PA']],

            ['GET', '/api/budgets', ['FO', 'HO']],
            ['GET', '/api/audit-logs', ['SA', 'HO']],

            ['GET', '/api/invoice-orders', ['FO']],
            ['POST', '/api/invoices', ['FO']],
            ['POST', '/api/budgets', ['FO']],
            ['PUT', '/api/budgets/999999', ['FO']],

            ['GET', '/api/users', ['SA']],
            ['POST', '/api/users', ['SA']],
            ['PUT', '/api/users/999999', ['SA']],

            ['POST', '/api/vendors', ['PA']],
            ['PUT', '/api/vendors/999999', ['PA']],
            ['POST', '/api/items', ['PA']],
            ['PUT', '/api/items/999999', ['PA']],
            ['POST', '/api/requests/999999/quotations', ['PA']],
            ['PUT', '/api/requests/999999/quotations/999999', ['PA']],
            ['DELETE', '/api/requests/999999/quotations/999999', ['PA']],
            ['POST', '/api/requests/999999/quotations/999999/select', ['PA']],
            ['POST', '/api/requests/999999/order', ['PA']],
            ['POST', '/api/invoices/999999/resolve', ['PA']],
        ];
    }

    public function test_every_route_allows_only_its_roles(): void
    {
        $problems = [];

        foreach (self::routes() as [$method, $uri, $allowed]) {
            foreach (self::USERS as $key => $username) {
                $status = $this->actingAs(User::where('username', $username)->first())->json($method, $uri)->getStatusCode();

                if (in_array($key, $allowed, true)) {
                    if (in_array($status, [401, 403], true) || $status >= 500) {
                        $problems[] = "$username should be allowed on $method $uri but got $status";
                    }
                } elseif ($status !== 403) {
                    $problems[] = "$username should be refused on $method $uri but got $status";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_every_route_needs_a_login(): void
    {
        $problems = [];

        foreach (self::routes() as [$method, $uri]) {
            $status = $this->json($method, $uri)->getStatusCode();
            if ($status !== 401) {
                $problems[] = "$method $uri answered $status without a login";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}