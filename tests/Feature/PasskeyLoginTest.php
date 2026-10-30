<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserPasskey;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeyLoginTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function enable(): void
    {
        Setting::where('key', 'passkey_login_enabled')->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();
    }

    public function test_disabled_by_default(): void
    {
        $this->postJson('/api/auth/passkey/options')->assertNotFound();
        $this->postJson('/api/auth/passkey/login', ['token' => 'x', 'credential' => ['id' => 'x']])->assertNotFound();
    }

    public function test_options_let_the_browser_pick_a_passkey_and_require_verification(): void
    {
        $this->enable();

        $data = $this->postJson('/api/auth/passkey/options')->assertOk()->json('data');

        $this->assertSame(64, strlen($data['token']));
        $this->assertSame('localhost', $data['options']['rpId']);
        $this->assertSame([], $data['options']['allowCredentials']);
        $this->assertSame('required', $data['options']['userVerification']);
    }

    public function test_invalid_or_replayed_assertions_are_refused(): void
    {
        $this->enable();
        $user = User::factory()->create();
        $passkey = new UserPasskey(['name' => 'Key']);
        $passkey->user_id = $user->id;
        $passkey->credential_id = 'AAAA';
        $passkey->credential_source = '{}';
        $passkey->save();

        $token = $this->postJson('/api/auth/passkey/options')->json('data.token');
        $bogus = ['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key', 'response' => ['userHandle' => (string) $user->id]];

        $this->postJson('/api/auth/passkey/login', ['token' => $token, 'credential' => $bogus])
            ->assertStatus(401)->assertJson(['code' => 'passkey_login_failed']);

        // the options are consumed even on failure
        $this->postJson('/api/auth/passkey/login', ['token' => $token, 'credential' => $bogus])->assertStatus(401);
        $this->postJson('/api/auth/passkey/login', ['token' => 'unknown', 'credential' => $bogus])->assertStatus(401);
    }
}
