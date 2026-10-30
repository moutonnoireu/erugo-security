<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\IpAllowlist;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const ALLOWED_IP = '203.0.113.10';
    private const OTHER_IP = '198.51.100.7';

    private function configureAllowlist(bool $enabled, string $list = self::ALLOWED_IP . "\n10.0.0.0/8"): void
    {
        Setting::where('key', IpAllowlist::SETTING_ENABLED)->update(['value' => $enabled ? 'true' : 'false']);
        Setting::where('key', IpAllowlist::SETTING_LIST)->update(['value' => $list]);
        app(SettingsService::class)->clearCache();
    }

    private function makeUser(array $attributes = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes))->save();

        return $user;
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . Auth::guard('api')->login($user)];
    }

    public function test_disabled_by_default(): void
    {
        $this->assertSame('false', Setting::where('key', IpAllowlist::SETTING_ENABLED)->value('value'));

        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_login_is_refused_from_an_ip_outside_the_list(): void
    {
        $user = $this->makeUser();
        $this->configureAllowlist(true);

        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJson(['code' => 'ip_not_allowed']);
    }

    public function test_login_works_from_an_allowed_ip_and_range(): void
    {
        $user = $this->makeUser();
        $this->configureAllowlist(true);

        $this->fromIp(self::ALLOWED_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->fromIp('10.20.30.40')
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_password_reset_is_refused_from_an_ip_outside_the_list(): void
    {
        $this->configureAllowlist(true);

        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/forgot-password', ['email' => 'someone@example.com'])
            ->assertStatus(403);
    }

    public function test_existing_session_of_a_member_is_refused_outside_the_list(): void
    {
        $user = $this->makeUser();
        $headers = $this->bearer($user);
        $this->configureAllowlist(true);

        $this->fromIp(self::OTHER_IP)
            ->getJson('/api/users/me', $headers)
            ->assertStatus(403)
            ->assertJson(['code' => 'ip_not_allowed']);

        $this->fromIp(self::ALLOWED_IP)
            ->getJson('/api/users/me', $headers)
            ->assertOk();
    }

    public function test_guest_users_are_not_restricted(): void
    {
        $guest = $this->makeUser(['is_guest' => true]);
        $headers = $this->bearer($guest);
        $this->configureAllowlist(true);

        $this->fromIp(self::OTHER_IP)
            ->getJson('/api/users/me', $headers)
            ->assertOk();
    }

    public function test_public_routes_are_not_restricted(): void
    {
        $this->configureAllowlist(true);

        $this->fromIp(self::OTHER_IP)->getJson('/api/health')->assertOk();
    }

    public function test_admin_cannot_enable_the_list_without_their_own_ip(): void
    {
        $admin = $this->makeUser(['admin' => true]);
        $headers = $this->bearer($admin);

        $this->fromIp(self::OTHER_IP)
            ->putJson('/api/settings', ['settings' => [
                ['key' => IpAllowlist::SETTING_ENABLED, 'value' => 'true'],
                ['key' => IpAllowlist::SETTING_LIST, 'value' => self::ALLOWED_IP],
            ]], $headers)
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_lockout']);

        $this->assertSame('false', Setting::where('key', IpAllowlist::SETTING_ENABLED)->value('value'));

        $this->fromIp(self::ALLOWED_IP)
            ->putJson('/api/settings', ['settings' => [
                ['key' => IpAllowlist::SETTING_ENABLED, 'value' => 'true'],
                ['key' => IpAllowlist::SETTING_LIST, 'value' => self::ALLOWED_IP],
            ]], $headers)
            ->assertOk();

        $this->assertSame('true', Setting::where('key', IpAllowlist::SETTING_ENABLED)->value('value'));
    }

    public function test_invalid_entries_are_rejected(): void
    {
        $admin = $this->makeUser(['admin' => true]);

        $this->putJson('/api/settings', ['settings' => [
            ['key' => IpAllowlist::SETTING_LIST, 'value' => "127.0.0.1\nnot-an-ip"],
        ]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_invalid', 'data' => ['invalid' => ['not-an-ip']]]);
    }

    public function test_security_settings_are_hidden_from_non_admins(): void
    {
        $user = $this->makeUser();

        $groups = collect($this->getJson('/api/settings/group/system.*', $this->bearer($user))
            ->assertOk()
            ->json('data.settings'))->pluck('group');

        $this->assertNotContains('system.security', $groups);
        $this->assertNotContains('system.smtp', $groups);
    }

    public function test_recovery_command_disables_the_list(): void
    {
        $this->configureAllowlist(true);

        $this->artisan('disable-ip-allowlist')->assertSuccessful();

        $this->assertSame('false', Setting::where('key', IpAllowlist::SETTING_ENABLED)->value('value'));
        $this->assertFalse(app(IpAllowlist::class)->isEnabled());
    }
}
