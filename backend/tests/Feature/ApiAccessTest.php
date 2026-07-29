<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CartLineInput;
use App\Enums\OrderType;
use App\Events\OrderPlaced;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The authorization boundaries.
 *
 * These are the tests that would catch a refactor accidentally widening
 * access — a cook seeing the till, or one guest reading another's order.
 */
class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Event::fake([OrderPlaced::class]);
    }

    // Public surface ----------------------------------------------------------

    public function test_the_menu_is_readable_without_authentication(): void
    {
        $this->makeProduct();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_bootstrap_is_public(): void
    {
        $this->makeBranch();

        $this->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.currency.code', 'IQD');
    }

    public function test_the_menu_serialises_the_requested_locale(): void
    {
        $this->makeProduct(['name_en' => 'Smash Burger', 'name_ar' => 'سماش برغر']);

        $this->withHeader('X-Locale', 'en')
            ->getJson('/api/v1/products')
            ->assertJsonPath('data.0.name', 'Smash Burger');

        $this->withHeader('X-Locale', 'ar')
            ->getJson('/api/v1/products')
            ->assertJsonPath('data.0.name', 'سماش برغر');
    }

    // Staff surfaces ----------------------------------------------------------

    public function test_staff_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/kitchen/board')->assertUnauthorized();
        $this->getJson('/api/v1/cashier/orders')->assertUnauthorized();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_a_cook_cannot_reach_the_till_or_the_admin_panel(): void
    {
        $branch = $this->makeBranch();
        $cook = $this->makeStaff('kitchen', $branch);

        $this->actingAs($cook, 'sanctum')->getJson('/api/v1/kitchen/board')->assertOk();
        $this->actingAs($cook, 'sanctum')->getJson('/api/v1/cashier/orders')->assertForbidden();
        $this->actingAs($cook, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_a_cashier_cannot_refund_without_the_permission(): void
    {
        $branch = $this->makeBranch();
        $cashier = $this->makeStaff('cashier', $branch);
        $order = $this->placeOrder($branch);

        // Refunds are a manager decision by design.
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/cashier/orders/{$order->order_number}/refund", [
                'amount' => 1000,
                'reason' => 'Test',
            ])
            ->assertForbidden();
    }

    public function test_a_manager_can_refund(): void
    {
        $branch = $this->makeBranch();
        $manager = $this->makeStaff('manager', $branch);
        $order = $this->placeOrder($branch);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/cashier/orders/{$order->order_number}/pay", [
                'method' => 'cash',
                'amount' => (float) $order->grand_total,
            ])
            ->assertCreated();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/cashier/orders/{$order->order_number}/refund", [
                'amount' => 1000,
                'reason' => 'Item was cold',
            ])
            ->assertOk();
    }

    public function test_a_cook_cannot_touch_another_branchs_order(): void
    {
        $home = $this->makeBranch(['slug' => 'home']);
        $other = $this->makeBranch(['slug' => 'other']);

        $cook = $this->makeStaff('kitchen', $home);
        $foreignOrder = $this->placeOrder($other);

        $this->actingAs($cook, 'sanctum')
            ->postJson("/api/v1/kitchen/orders/{$foreignOrder->order_number}/advance")
            ->assertForbidden();
    }

    public function test_a_deactivated_account_is_rejected(): void
    {
        $branch = $this->makeBranch();
        $cook = $this->makeStaff('kitchen', $branch);
        $cook->forceFill(['is_active' => false])->save();

        $this->actingAs($cook, 'sanctum')
            ->getJson('/api/v1/kitchen/board')
            ->assertForbidden()
            ->assertJsonPath('error', 'account_inactive');
    }

    // Guest ownership ---------------------------------------------------------

    public function test_a_guest_can_place_and_track_their_own_order(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 5000]);
        $token = $this->guestToken();

        $response = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/v1/orders', [
                'branch_id' => $branch->id,
                'type' => 'takeaway',
                'customer_phone' => '+9647700000000',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $orderNumber = $response->json('data.order_number');

        $this->withHeader('X-Guest-Token', $token)
            ->getJson("/api/v1/orders/{$orderNumber}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $orderNumber);
    }

    public function test_another_guest_cannot_read_that_order(): void
    {
        $branch = $this->makeBranch();
        $order = $this->placeOrder($branch, $this->guestToken());

        $this->withHeader('X-Guest-Token', $this->guestToken())
            ->getJson("/api/v1/orders/{$order->order_number}")
            ->assertNotFound();
    }

    public function test_an_anonymous_caller_with_no_token_sees_nothing(): void
    {
        $branch = $this->makeBranch();
        $order = $this->placeOrder($branch);

        $this->getJson("/api/v1/orders/{$order->order_number}")->assertNotFound();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_dine_in_order_requires_a_scanned_table(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();

        $this->withHeader('X-Guest-Token', $this->guestToken())
            ->postJson('/api/v1/orders', [
                'branch_id' => $branch->id,
                'type' => 'dine_in',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'table_required');
    }

    public function test_scanning_a_qr_token_opens_a_session(): void
    {
        $branch = $this->makeBranch();
        $table = $this->makeTable($branch, ['number' => '7']);

        $this->postJson("/api/v1/tables/scan/{$table->qr_token}", ['party_size' => 2])
            ->assertOk()
            ->assertJsonPath('data.table.number', '7')
            ->assertJsonStructure(['data' => ['session_token', 'guest_token', 'branch']]);
    }

    public function test_scanning_twice_rejoins_the_same_session(): void
    {
        $branch = $this->makeBranch();
        $table = $this->makeTable($branch);

        $first = $this->postJson("/api/v1/tables/scan/{$table->qr_token}")->json('data.session_token');
        $second = $this->postJson("/api/v1/tables/scan/{$table->qr_token}")->json('data.session_token');

        // A second person at the table must join the bill, not start a new one.
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_an_unknown_qr_token_is_rejected(): void
    {
        $this->postJson('/api/v1/tables/scan/not-a-real-token')
            ->assertNotFound()
            ->assertJsonPath('error', 'table_not_found');
    }

    public function test_the_cart_endpoint_prices_server_side(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 7500]);

        $this->postJson('/api/v1/cart/price', [
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('data.grand_total', 15000);
    }

    // Auth --------------------------------------------------------------------

    public function test_login_returns_a_token_and_the_users_permissions(): void
    {
        $branch = $this->makeBranch();
        $cashier = $this->makeStaff('cashier', $branch);

        $this->postJson('/api/v1/auth/login', [
            'login' => $cashier->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['roles', 'permissions']]])
            ->assertJsonPath('data.user.roles.0', 'cashier');
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $cashier = $this->makeStaff('cashier', $this->makeBranch());

        $this->postJson('/api/v1/auth/login', [
            'login' => $cashier->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_signing_in_claims_the_devices_guest_orders(): void
    {
        $branch = $this->makeBranch();
        $token = $this->guestToken();
        $order = $this->placeOrder($branch, $token);

        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/auth/login', [
            'login' => $customer->email,
            'password' => 'password',
            'guest_token' => $token,
        ])->assertOk();

        $this->assertSame($customer->id, $order->fresh()->user_id);
    }

    private function placeOrder($branch, ?string $guestToken = null)
    {
        $product = $this->makeProduct(['base_price' => 10000]);

        $cart = app(CartPricingService::class)->price(
            [new CartLineInput($product->id, 1)],
            $branch,
        );

        return app(OrderCreationService::class)->create(
            cart: $cart,
            branch: $branch,
            type: OrderType::Takeaway,
            guestToken: $guestToken ?? $this->guestToken(),
        );
    }
}
