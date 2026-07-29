<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proxy header handling.
 *
 * The API is never reached directly — nginx sits in front under Compose, the
 * platform edge does on Railway. If X-Forwarded-* is not trusted, every request
 * appears to originate from the proxy, and because the rate limiters key on
 * $request->ip() the entire restaurant ends up sharing one 10/minute login
 * bucket. These assertions fail if that trust is removed.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_the_client_address_is_taken_from_the_forwarded_header(): void
    {
        $this->makeBranch();

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.7',            // the proxy
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9', // the actual customer
        ])->getJson('/api/v1/bootstrap')->assertOk();

        $this->assertSame('203.0.113.9', request()->ip());
    }

    public function test_two_clients_behind_one_proxy_do_not_share_a_login_bucket(): void
    {
        $branch = $this->makeBranch();
        $cashier = $this->makeStaff('cashier', $branch);

        // One member of staff burns the whole auth allowance on bad passwords.
        for ($i = 0; $i < 12; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.7',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ])->postJson('/api/v1/auth/login', [
                'login' => $cashier->email,
                'password' => 'wrong-password',
            ]);
        }

        // A colleague on the next till, behind the same proxy, must still be
        // able to sign in.
        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.7',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.4',
        ])->postJson('/api/v1/auth/login', [
            'login' => $cashier->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_the_offender_is_still_rate_limited(): void
    {
        $branch = $this->makeBranch();
        $cashier = $this->makeStaff('cashier', $branch);

        $status = null;
        for ($i = 0; $i < 12; $i++) {
            $status = $this->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.7',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ])->postJson('/api/v1/auth/login', [
                'login' => $cashier->email,
                'password' => 'wrong-password',
            ])->getStatusCode();
        }

        // Per-client limiting still has to bite, or this is just a hole.
        $this->assertSame(429, $status);
    }

    public function test_a_forwarded_https_scheme_marks_the_request_secure(): void
    {
        $this->makeBranch();

        // HSTS is emitted only on a secure request; behind TLS termination the
        // proxy header is the only evidence there is.
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/api/v1/bootstrap')->assertOk();

        $this->assertTrue(request()->secure());
        $response->assertHeader('Strict-Transport-Security');
    }
}
