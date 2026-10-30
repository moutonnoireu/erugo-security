<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPasskey;
use App\Services\Totp;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeUser(array $attributes = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes))->save();

        return $user;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . Auth::guard('api')->login($user)];
    }

    /** Enable TOTP through the API and return [secret, recovery codes]. */
    private function enableTotp(User $user): array
    {
        $setup = $this->postJson('/api/users/me/2fa/totp/setup', [], $this->bearer($user))->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);

        $codes = $this->postJson('/api/users/me/2fa/totp/confirm', [
            'code' => Totp::codeAt($setup['secret'], Totp::timestep() - 1),
        ], $this->bearer($user))->assertOk()->json('data.recovery_codes');

        return [$setup['secret'], $codes];
    }

    private function startLogin(User $user): string
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJson(['status' => 'mfa_required'])
            ->assertJsonMissingPath('data.access_token')
            ->json('data.mfa_token');
    }

    public function test_login_without_second_factor_is_unchanged(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_wrong_password_is_still_refused(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nope'])->assertStatus(401);
    }

    public function test_setup_rejects_a_wrong_code(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/users/me/2fa/totp/setup', [], $this->bearer($user))->assertOk();

        $this->postJson('/api/users/me/2fa/totp/confirm', ['code' => '000000'], $this->bearer($user))
            ->assertStatus(422);

        $this->assertNull($user->fresh()->totp_enabled_at);
    }

    public function test_totp_sign_in_flow(): void
    {
        $user = $this->makeUser();
        [$secret, $recovery] = $this->enableTotp($user);

        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $recovery);
        $this->assertNotNull($user->fresh()->totp_enabled_at);
        $this->assertNotSame($secret, $user->fresh()->getRawOriginal('totp_secret'), 'secret must be encrypted at rest');

        $token = $this->startLogin($user);
        $code = Totp::codeAt($secret, Totp::timestep());

        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertCookie('refresh_token');

        // the challenge token is single use
        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => $code])
            ->assertStatus(401)->assertJson(['code' => 'mfa_expired']);

        // the same code cannot be replayed on a new sign-in
        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $this->startLogin($user), 'method' => 'totp', 'code' => $code])
            ->assertStatus(401)->assertJson(['code' => 'mfa_invalid_code']);
    }

    public function test_recovery_codes_work_once(): void
    {
        $user = $this->makeUser();
        [, $recovery] = $this->enableTotp($user);

        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $this->startLogin($user), 'method' => 'recovery', 'code' => strtoupper($recovery[0])])
            ->assertOk();

        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $this->startLogin($user), 'method' => 'recovery', 'code' => $recovery[0]])
            ->assertStatus(401);

        $this->assertSame(TwoFactor::RECOVERY_CODE_COUNT - 1, app(TwoFactor::class)->remainingRecoveryCodes($user->fresh()));
    }

    public function test_challenge_is_dropped_after_too_many_wrong_codes(): void
    {
        $user = $this->makeUser();
        [$secret] = $this->enableTotp($user);
        $token = $this->startLogin($user);

        for ($i = 0; $i < TwoFactor::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => '000000'])
                ->assertStatus(401);
        }

        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => Totp::codeAt($secret, Totp::timestep())])
            ->assertStatus(401)->assertJson(['code' => 'mfa_expired']);
    }

    public function test_a_disabled_account_cannot_finish_sign_in(): void
    {
        $user = $this->makeUser();
        [$secret] = $this->enableTotp($user);
        $token = $this->startLogin($user);
        $user->forceFill(['active' => false])->save();

        $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => Totp::codeAt($secret, Totp::timestep())])
            ->assertStatus(401);
    }

    public function test_disabling_requires_the_password(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $this->deleteJson('/api/users/me/2fa/totp', ['password' => 'wrong'], $this->bearer($user))->assertStatus(422);
        $this->assertNotNull($user->fresh()->totp_enabled_at);

        $this->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $this->bearer($user))
            ->assertOk()->assertJsonPath('data.enabled', false);

        $fresh = $user->fresh();
        $this->assertNull($fresh->totp_enabled_at);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertJsonPath('status', 'success');
    }

    public function test_status_never_exposes_secrets(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $status = $this->getJson('/api/users/me/2fa', $this->bearer($user))->assertOk();
        $status->assertJsonPath('data.totp_enabled', true)->assertJsonPath('data.recovery_codes_remaining', TwoFactor::RECOVERY_CODE_COUNT);

        $profile = json_encode($this->getJson('/api/users/me', $this->bearer($user))->json());
        $this->assertStringNotContainsString('totp_secret', $profile);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $profile);
    }

    public function test_admin_can_reset_a_user_and_members_cannot(): void
    {
        $admin = $this->makeUser(['admin' => true]);
        $member = $this->makeUser();
        $this->enableTotp($member);

        $this->deleteJson("/api/users/{$member->id}/2fa", [], $this->bearer($this->makeUser()))->assertStatus(403);
        $this->assertNotNull($member->fresh()->totp_enabled_at);

        $this->deleteJson("/api/users/{$member->id}/2fa", [], $this->bearer($admin))->assertOk();
        $this->assertNull($member->fresh()->totp_enabled_at);
    }

    public function test_recovery_command_resets_a_user(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $this->artisan('reset-2fa', ['email' => $user->email])->assertSuccessful();
        $this->assertFalse(app(TwoFactor::class)->isEnabledFor($user->fresh()));
    }

    public function test_passkey_options_are_bound_to_the_host(): void
    {
        $user = $this->makeUser();

        $options = $this->postJson('/api/users/me/passkeys/options', [], $this->bearer($user))->assertOk()->json('data.options');
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame((string) $user->id, base64_decode(strtr($options['user']['id'], '-_', '+/')));
        $this->assertSame('none', $options['attestation']);

        // registration with a bogus credential fails cleanly
        $this->postJson('/api/users/me/passkeys', ['name' => 'Key', 'credential' => ['id' => 'x', 'type' => 'public-key']], $this->bearer($user))
            ->assertStatus(422)->assertJson(['code' => 'passkey_registration_failed']);
        $this->assertSame(0, UserPasskey::count());
    }

    public function test_passkey_login_requires_a_valid_assertion(): void
    {
        $user = $this->makeUser();
        // a stored passkey makes the second factor mandatory
        $passkey = new UserPasskey(['name' => 'Key']);
        $passkey->user_id = $user->id;
        $passkey->credential_id = 'AAAA';
        $passkey->credential_source = '{}';
        $passkey->save();

        $token = $this->startLogin($user);
        $this->postJson('/api/auth/mfa/passkey', ['mfa_token' => $token, 'credential' => ['id' => 'AAAA', 'type' => 'public-key']])
            ->assertStatus(401);
    }

    public function test_guests_and_external_sign_in_are_not_affected(): void
    {
        // external providers and reverse-share guests never go through the password form
        $guest = $this->makeUser(['is_guest' => true]);
        $this->getJson('/api/users/me', $this->bearer($guest))->assertOk();
    }
}
