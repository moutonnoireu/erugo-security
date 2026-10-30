<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Self-hosted ALTCHA proof-of-work challenges (https://altcha.org), no third party.
 *
 * Implements the SHA-256 challenge format (challenge = sha256(salt + number),
 * HMAC-signed), which the ALTCHA widget v1, v2 and v3 all solve. The HMAC key is
 * derived from APP_KEY, challenges expire, and each solution is accepted once.
 *
 * Settings (group "system.security"):
 *  - altcha_enabled     "true" | "false" (default "false")
 *  - altcha_max_number  difficulty: the widget tries up to this many numbers (default 100000)
 */
class Altcha
{
    public const SETTING_ENABLED = 'altcha_enabled';
    public const SETTING_MAX_NUMBER = 'altcha_max_number';

    public const DEFAULT_MAX_NUMBER = 100000;
    public const MIN_MAX_NUMBER = 1000;
    public const MAX_MAX_NUMBER = 10000000;

    /** Seconds a challenge stays valid. */
    public const TTL = 600;

    private const ALGORITHM = 'SHA-256';

    public function __construct(private SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::SETTING_ENABLED) === true;
    }

    public function maxNumber(): int
    {
        $value = (int) ($this->settings->get(self::SETTING_MAX_NUMBER) ?? self::DEFAULT_MAX_NUMBER);

        return max(self::MIN_MAX_NUMBER, min(self::MAX_MAX_NUMBER, $value ?: self::DEFAULT_MAX_NUMBER));
    }

    /**
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
     */
    public function createChallenge(?int $now = null): array
    {
        $now ??= time();
        $maxNumber = $this->maxNumber();

        // The expiry is part of the salt, so it is covered by the hash and the signature.
        $salt = bin2hex(random_bytes(12)) . '?expires=' . ($now + self::TTL);
        $number = random_int(0, $maxNumber);
        $challenge = hash('sha256', $salt . $number);

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => $maxNumber,
            'salt' => $salt,
            'signature' => $this->sign($challenge),
        ];
    }

    /**
     * Check a base64 payload sent by the widget. A valid payload is consumed:
     * submitting it a second time fails.
     */
    public function verify(?string $payload, ?int $now = null): bool
    {
        $now ??= time();

        if (!is_string($payload) || $payload === '' || strlen($payload) > 4096) {
            return false;
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data)) {
            return false;
        }

        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field) {
            if (!array_key_exists($field, $data)) {
                return false;
            }
        }

        [$algorithm, $challenge, $number, $salt, $signature] = [
            $data['algorithm'], $data['challenge'], $data['number'], $data['salt'], $data['signature'],
        ];

        if ($algorithm !== self::ALGORITHM || !is_string($challenge) || !is_string($salt) || !is_string($signature)) {
            return false;
        }

        if (!is_int($number) || $number < 0) {
            return false;
        }

        $expires = $this->expiresFromSalt($salt);
        if ($expires === null || $expires < $now) {
            return false;
        }

        if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
            return false;
        }

        if (!hash_equals($this->sign($challenge), $signature)) {
            return false;
        }

        // Single use: the first request to record the challenge wins.
        return Cache::add('altcha:used:' . $challenge, true, max(1, $expires - $now));
    }

    private function sign(string $challenge): string
    {
        return hash_hmac('sha256', $challenge, $this->hmacKey());
    }

    private function hmacKey(): string
    {
        return hash_hmac('sha256', 'erugo-altcha', (string) config('app.key'));
    }

    private function expiresFromSalt(string $salt): ?int
    {
        $query = parse_url('http://x/?' . (explode('?', $salt, 2)[1] ?? ''), PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $expires = $params['expires'] ?? null;

        return is_string($expires) && ctype_digit($expires) ? (int) $expires : null;
    }
}
