<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Totp;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function policy(string $value): void
    {
        Setting::where('key', TwoFactor::SETTING_REQUIRED_FOR)->update(['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    private function makeUser(array $attributes = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes))->save();

        return $user;
    }

    private function login(User $user)
    {
        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_default_policy_requires_nothing(): void
    {
        $this->assertSame('none', Setting::where('key', TwoFactor::SETTING_REQUIRED_FOR)->value('value'));
        $this->login($this->makeUser(['admin' => true]))->assertJsonPath('status', 'success');
    }

    public function test_admins_policy_only_targets_admins(): void
    {
        $this->policy('admins');

        $this->login($this->makeUser())->assertJsonPath('status', 'success');
        $this->login($this->makeUser(['admin' => true]))
            ->assertJsonPath('status', 'mfa_enrolment_required')
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_enrolment_with_an_authenticator_app_opens_the_session(): void
    {
        $this->policy('everyone');
        $user = $this->makeUser();
        $token = $this->login($user)->assertJsonPath('status', 'mfa_enrolment_required')->json('data.enrol_token');

        $setup = $this->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])->assertOk()->json('data');

        $this->postJson('/api/auth/mfa/enrol/totp/confirm', ['enrol_token' => $token, 'code' => '000000'])
            ->assertStatus(422);

        $response = $this->postJson('/api/auth/mfa/enrol/totp/confirm', [
            'enrol_token' => $token,
            'code' => Totp::codeAt($setup['secret'], Totp::timestep()),
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'recovery_codes']]);
        $this->assertCount(TwoFactor::RECOVERY_CODE_COUNT, $response->json('data.recovery_codes'));
        $response->assertCookie('refresh_token');

        // the enrolment token is single use
        $this->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])
            ->assertStatus(401)->assertJson(['code' => 'mfa_enrolment_expired']);

        // next sign-in asks for the second factor as usual
        $this->login($user)->assertJsonPath('status', 'mfa_required');
    }

    public function test_enrolment_token_gives_no_access_to_the_api(): void
    {
        $this->policy('everyone');
        $token = $this->login($this->makeUser())->json('data.enrol_token');

        $this->getJson('/api/users/me', ['Authorization' => 'Bearer ' . $token])->assertStatus(401);
    }

    public function test_the_last_factor_cannot_be_removed_while_required(): void
    {
        $user = $this->makeUser();
        $headers = ['Authorization' => 'Bearer ' . Auth::guard('api')->login($user)];
        $setup = $this->postJson('/api/users/me/2fa/totp/setup', [], $headers)->json('data');
        $this->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($setup['secret'], Totp::timestep())], $headers)->assertOk();

        $this->policy('everyone');

        $this->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $headers)
            ->assertStatus(422)->assertJson(['code' => 'mfa_required_by_policy']);
        $this->assertNotNull($user->fresh()->totp_enabled_at);

        $this->policy('none');
        $this->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $headers)->assertOk();
    }

    public function test_password_sessions_are_not_renewed_without_a_second_factor(): void
    {
        $user = $this->makeUser();
        $refresh = $this->login($user)->getCookie('refresh_token', false)->getValue();

        // still allowed while the policy is off
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/refresh')->assertOk();

        $this->policy('everyone');
        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/refresh')
            ->assertStatus(401)->assertJson(['code' => 'mfa_enrolment_required']);
    }

    public function test_sso_sessions_are_renewed(): void
    {
        $this->policy('everyone');
        $user = $this->makeUser();
        $refresh = Auth::guard('api')->claims(['amr' => 'sso'])->tokenById($user->id);

        $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/auth/refresh')->assertOk();
    }

    public function test_guests_are_never_required(): void
    {
        $this->policy('everyone');
        $this->assertFalse(app(TwoFactor::class)->isRequiredFor($this->makeUser(['is_guest' => true])));
    }

    public function test_invalid_policy_values_are_rejected(): void
    {
        $admin = $this->makeUser(['admin' => true]);

        $this->putJson('/api/settings', ['settings' => [['key' => TwoFactor::SETTING_REQUIRED_FOR, 'value' => 'sometimes']]], [
            'Authorization' => 'Bearer ' . Auth::guard('api')->login($admin),
        ])->assertStatus(422)->assertJson(['code' => 'invalid_mfa_policy']);
    }
}
