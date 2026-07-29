<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Private-channel authorization.
 *
 * Regression guard. Registering channels through withRouting() leaves
 * /broadcasting/auth behind the `web` middleware, which authenticates from a
 * session cookie. The frontend is a separate origin holding a Sanctum bearer
 * token and sends no session cookie, so every subscribe was answered 403 and
 * every realtime screen quietly degraded to its polling fallback — visibly
 * working, never actually live. These tests fail if that regresses.
 */
class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        // The suite runs on the `null` broadcaster, which authorizes every
        // channel without consulting routes/channels.php — so these assertions
        // would pass no matter what the callbacks said. Point at a real
        // Pusher-protocol driver (Reverb speaks it) so the callbacks run and a
        // signed payload comes back.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channels are registered against whichever broadcaster was default
        // when the application booted, and switching the config above resolves
        // a *new* driver instance with an empty channel list. Without this the
        // callbacks are never consulted and every case returns 403 — including
        // the ones that should succeed.
        require base_path('routes/channels.php');
    }

    public function test_a_bearer_token_can_authorize_its_own_branch_channel(): void
    {
        $branch = $this->makeBranch();
        $cook = $this->makeStaff('kitchen', $branch);

        $this->actingAs($cook, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-branches.{$branch->id}.kitchen",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_an_anonymous_subscriber_is_rejected(): void
    {
        $branch = $this->makeBranch();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-branches.{$branch->id}.kitchen",
        ])->assertUnauthorized();
    }

    public function test_a_cook_cannot_subscribe_to_another_branchs_kitchen(): void
    {
        $home = $this->makeBranch(['slug' => 'home']);
        $other = $this->makeBranch(['slug' => 'other']);
        $cook = $this->makeStaff('kitchen', $home);

        $this->actingAs($cook, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-branches.{$other->id}.kitchen",
            ])
            ->assertForbidden();
    }

    public function test_a_cook_cannot_subscribe_to_the_cashier_channel(): void
    {
        $branch = $this->makeBranch();
        $cook = $this->makeStaff('kitchen', $branch);

        // Channel authorization re-checks the permission; holding a valid token
        // is not on its own enough to listen to the till.
        $this->actingAs($cook, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-branches.{$branch->id}.cashier",
            ])
            ->assertForbidden();
    }

    public function test_a_deactivated_account_cannot_subscribe(): void
    {
        $branch = $this->makeBranch();
        $cook = $this->makeStaff('kitchen', $branch);
        $cook->forceFill(['is_active' => false])->save();

        // The `active` middleware runs on this route too, so a sacked employee
        // stops receiving tickets immediately rather than when their token ages
        // out.
        $this->actingAs($cook, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-branches.{$branch->id}.kitchen",
            ])
            ->assertForbidden();
    }

    public function test_a_customer_can_authorize_their_own_order_feed(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-users.{$customer->id}.orders",
            ])
            ->assertOk();
    }

    public function test_a_customer_cannot_authorize_someone_elses_order_feed(): void
    {
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer(['email' => 'other@viking.example']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-users.{$other->id}.orders",
            ])
            ->assertForbidden();
    }
}
