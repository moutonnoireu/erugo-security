<?php

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238 appendix B test secret "12345678901234567890" (SHA-1), last 6 digits
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_rfc_6238_vectors(): void
    {
        $this->assertSame('287082', Totp::codeAt(self::SECRET, Totp::timestep(59)));
        $this->assertSame('081804', Totp::codeAt(self::SECRET, Totp::timestep(1111111109)));
        $this->assertSame('050471', Totp::codeAt(self::SECRET, Totp::timestep(1111111111)));
        $this->assertSame('005924', Totp::codeAt(self::SECRET, Totp::timestep(1234567890)));
        $this->assertSame('279037', Totp::codeAt(self::SECRET, Totp::timestep(2000000000)));
    }

    public function test_verify_accepts_one_step_of_drift_and_refuses_replays(): void
    {
        $time = 1111111109;
        $step = Totp::timestep($time);

        $this->assertSame($step, Totp::verify(self::SECRET, '081804', null, $time));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 1), null, $time));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 2), null, $time));
        $this->assertNull(Totp::verify(self::SECRET, '081804', $step, $time), 'already used step');
        $this->assertNull(Totp::verify(self::SECRET, 'abcdef', null, $time));
        $this->assertSame($step, Totp::verify(self::SECRET, '081 804', null, $time), 'spaces are ignored');
    }

    public function test_secret_and_uri(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $uri = Totp::provisioningUri($secret, 'me@example.com', 'My Erugo');
        $this->assertStringStartsWith('otpauth://totp/My%20Erugo:me%40example.com?secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=My%20Erugo', $uri);
    }
}
