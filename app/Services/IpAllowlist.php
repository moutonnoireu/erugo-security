<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional restriction of sign-in to a list of IP addresses / CIDR ranges.
 *
 * Settings (group "system.security"):
 *  - login_ip_allowlist_enabled  "true" | "false" (default "false")
 *  - login_ip_allowlist          one entry per line (or comma separated),
 *                                IPv4/IPv6 address or CIDR range, "#" starts a comment
 */
class IpAllowlist
{
    public const SETTING_ENABLED = 'login_ip_allowlist_enabled';
    public const SETTING_LIST = 'login_ip_allowlist';

    public function __construct(private SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::SETTING_ENABLED) === true;
    }

    /** @return string[] */
    public function entries(): array
    {
        return self::parse((string) ($this->settings->get(self::SETTING_LIST) ?? ''));
    }

    /**
     * True when the restriction is active and $ip is not covered by the list.
     */
    public function blocks(?string $ip): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return !self::matches($ip, $this->entries());
    }

    /**
     * Split a raw setting value into entries, dropping blanks and comments.
     *
     * @return string[]
     */
    public static function parse(string $raw): array
    {
        $entries = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line !== '') {
                $entries[] = $line;
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * Return the entries that are not a valid IP address or CIDR range.
     *
     * @param string[] $entries
     * @return string[]
     */
    public static function invalidEntries(array $entries): array
    {
        return array_values(array_filter($entries, fn ($entry) => !self::isValidEntry($entry)));
    }

    public static function isValidEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (substr_count($entry, '/') !== 1) {
            return false;
        }

        [$address, $mask] = explode('/', $entry);
        if (!ctype_digit($mask)) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (int) $mask <= 32;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (int) $mask <= 128;
        }

        return false;
    }

    /**
     * @param string[] $entries
     */
    public static function matches(?string $ip, array $entries): bool
    {
        if ($ip === null || $ip === '' || $entries === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $entries);
    }
}
