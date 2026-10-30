<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base32;

/**
 * Second factor for password sign-in: authenticator app (TOTP), passkeys
 * (WebAuthn) and single-use recovery codes. Opt-in per user.
 *
 * After a correct password, a user with a second factor gets a short-lived
 * "login challenge" token instead of a session; the session is issued once
 * the second factor is verified against that token.
 */
class TwoFactor
{
    public const CHALLENGE_TTL = 300;
    public const ENROLMENT_TTL = 900;

    /** Admin policy: who must use a second factor ("none", "admins", "everyone"). */
    public const SETTING_REQUIRED_FOR = 'mfa_required_for';
    public const POLICIES = ['none', 'admins', 'everyone'];
    public const MAX_ATTEMPTS = 5;
    public const RECOVERY_CODE_COUNT = 10;

    public function __construct(private SettingsService $settings)
    {
    }

    /** The admin policy requires a second factor for this user. */
    public function isRequiredFor(User $user): bool
    {
        if ($user->is_guest) {
            return false;
        }

        return match ($this->settings->get(self::SETTING_REQUIRED_FOR)) {
            'everyone' => true,
            'admins' => (bool) $user->admin,
            default => false,
        };
    }

    // ----- mandatory enrolment ---------------------------------------------

    public function createEnrolmentChallenge(User $user): string
    {
        $token = Str::random(64);
        Cache::put($this->enrolmentKey($token), $user->id, self::ENROLMENT_TTL);

        return $token;
    }

    public function userForEnrolment(?string $token): ?User
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $id = Cache::get($this->enrolmentKey($token));
        $user = $id ? User::find($id) : null;

        return $user && $user->active ? $user : null;
    }

    public function completeEnrolment(string $token): void
    {
        Cache::forget($this->enrolmentKey($token));
    }

    private function enrolmentKey(string $token): string
    {
        return 'mfa:enrol:' . hash('sha256', $token);
    }

    public function isEnabledFor(User $user): bool
    {
        return $user->totp_enabled_at !== null || $user->passkeys()->exists();
    }

    /** @return string[] */
    public function methodsFor(User $user): array
    {
        $methods = [];
        if ($user->totp_enabled_at !== null) {
            $methods[] = 'totp';
        }
        if ($user->passkeys()->exists()) {
            $methods[] = 'passkey';
        }
        if ($this->remainingRecoveryCodes($user) > 0) {
            $methods[] = 'recovery';
        }

        return $methods;
    }

    // ----- login challenge -------------------------------------------------

    public function createLoginChallenge(User $user): string
    {
        $token = Str::random(64);
        Cache::put($this->challengeKey($token), ['user_id' => $user->id, 'attempts' => 0], self::CHALLENGE_TTL);

        return $token;
    }

    public function userForChallenge(?string $token): ?User
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $data = Cache::get($this->challengeKey($token));
        if (!is_array($data)) {
            return null;
        }

        $user = User::find($data['user_id']);

        return $user && $user->active ? $user : null;
    }

    /** Count a failed attempt; the challenge is dropped after MAX_ATTEMPTS. */
    public function failChallenge(string $token): void
    {
        $key = $this->challengeKey($token);
        $data = Cache::get($key);
        if (!is_array($data)) {
            return;
        }

        $data['attempts']++;
        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return;
        }

        Cache::put($key, $data, self::CHALLENGE_TTL);
    }

    public function completeChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        return 'mfa:login:' . hash('sha256', $token);
    }

    // ----- TOTP ------------------------------------------------------------

    public function verifyTotp(User $user, string $code): bool
    {
        if ($user->totp_enabled_at === null || !$user->totp_secret) {
            return false;
        }

        $step = Totp::verify($user->totp_secret, $code, $user->totp_last_timestep);
        if ($step === null) {
            return false;
        }

        $user->forceFill(['totp_last_timestep' => $step])->save();

        return true;
    }

    // ----- recovery codes --------------------------------------------------

    /**
     * Replace the user's recovery codes and return the new plain codes
     * (shown once; only hashes are stored).
     *
     * @return string[]
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtolower(Base32::encodeUnpadded(random_bytes(7))); // 56 bits
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 6);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(array_map([$this, 'hashRecoveryCode'], $codes)),
        ])->save();

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $this->recoveryCodeHashes($user);
        $candidate = $this->hashRecoveryCode($code);

        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();
                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->recoveryCodeHashes($user));
    }

    /** @return string[] */
    private function recoveryCodeHashes(User $user): array
    {
        $decoded = json_decode((string) $user->two_factor_recovery_codes, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function hashRecoveryCode(string $code): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));

        return hash('sha256', $normalized);
    }

    /**
     * Remove every second factor of a user (admin reset, lost device).
     */
    public function disableAll(User $user): void
    {
        $user->passkeys()->delete();
        $user->forceFill([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_timestep' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }
}
