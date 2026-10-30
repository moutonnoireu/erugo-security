<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TusdHooksTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function hook(string $remoteAddr, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->postJson('/api/tusd-hooks', ['Type' => 'post-receive'], $headers);
    }

    public function test_loopback_is_accepted(): void
    {
        $this->hook('127.0.0.1')->assertOk()->assertJson(['ok' => true]);
        $this->hook('::1')->assertOk();
    }

    public function test_private_and_proxy_addresses_are_refused(): void
    {
        // behind a reverse proxy every visitor arrives from a private address
        foreach (['172.17.0.1', '10.0.0.5', '192.168.1.20', '172.200.1.1', '203.0.113.9'] as $ip) {
            $this->hook($ip)->assertStatus(403);
        }
    }

    public function test_forwarded_headers_cannot_fake_loopback(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->hook('172.17.0.1', ['X-Forwarded-For' => '127.0.0.1'])->assertStatus(403);
    }
}
