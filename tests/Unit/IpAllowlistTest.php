<?php

namespace Tests\Unit;

use App\Services\IpAllowlist;
use PHPUnit\Framework\TestCase;

class IpAllowlistTest extends TestCase
{
    public function test_parse_splits_lines_and_commas_and_drops_comments(): void
    {
        $raw = "203.0.113.10\n# office\n198.51.100.0/24, 2001:db8::/32  # VPN\n\n  ";

        $this->assertSame(
            ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'],
            IpAllowlist::parse($raw)
        );
    }

    public function test_validates_addresses_and_ranges(): void
    {
        foreach (['10.0.0.1', '10.0.0.0/8', '0.0.0.0/0', '::1', '2001:db8::/32', '2001:db8::1/128'] as $ok) {
            $this->assertTrue(IpAllowlist::isValidEntry($ok), $ok);
        }

        foreach (['10.0.0', '10.0.0.0/33', '2001:db8::/129', 'example.com', '10.0.0.0/8/1', '10.0.0.0/x', '*'] as $bad) {
            $this->assertFalse(IpAllowlist::isValidEntry($bad), $bad);
        }

        $this->assertSame(['nope'], IpAllowlist::invalidEntries(['10.0.0.1', 'nope']));
    }

    public function test_matches_ipv4_and_ipv6(): void
    {
        $entries = ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'];

        $this->assertTrue(IpAllowlist::matches('203.0.113.10', $entries));
        $this->assertTrue(IpAllowlist::matches('198.51.100.42', $entries));
        $this->assertTrue(IpAllowlist::matches('2001:db8:1::5', $entries));

        $this->assertFalse(IpAllowlist::matches('203.0.113.11', $entries));
        $this->assertFalse(IpAllowlist::matches('2001:db9::1', $entries));
        $this->assertFalse(IpAllowlist::matches(null, $entries));
        $this->assertFalse(IpAllowlist::matches('203.0.113.10', []));
    }
}
