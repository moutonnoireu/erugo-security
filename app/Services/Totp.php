<?php

namespace App\Services;

use ParagonIE\ConstantTime\Base32;

/**
 * RFC 6238 time-based one-time passwords (SHA-1, 6 digits, 30 seconds),
 * the format used by every authenticator app.
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** Accepted clock drift, in time steps, on each side. */
    public const WINDOW = 1;

    public static function generateSecret(): string
    {
        // 160 bits, as recommended by RFC 4226
        return Base32::encodeUpperUnpadded(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function codeAt(string $secret, int $timestep): string
    {
        $key = Base32::decodeUpper(strtoupper($secret));
        $hash = hash_hmac('sha1', pack('J', $timestep), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Return the matching time step, or null. A step at or before
     * $lastUsedTimestep is refused so that a code cannot be replayed.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedTimestep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $current = self::timestep($time);
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($lastUsedTimestep !== null && $step <= $lastUsedTimestep) {
                continue;
            }
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }
}
