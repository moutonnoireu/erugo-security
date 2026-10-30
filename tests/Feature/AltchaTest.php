<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\Altcha;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AltchaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function enableAltcha(int $maxNumber = 1000): void
    {
        Setting::where('key', Altcha::SETTING_ENABLED)->update(['value' => 'true']);
        Setting::where('key', Altcha::SETTING_MAX_NUMBER)->update(['value' => (string) $maxNumber]);
        app(SettingsService::class)->clearCache();
    }

    /**
     * Solve a challenge the way the widget does and return the base64 payload.
     */
    private function solve(array $challenge, ?callable $tamper = null): string
    {
        for ($number = 0; $number <= $challenge['maxnumber']; $number++) {
            if (hash('sha256', $challenge['salt'] . $number) === $challenge['challenge']) {
                $payload = [
                    'algorithm' => $challenge['algorithm'],
                    'challenge' => $challenge['challenge'],
                    'number' => $number,
                    'salt' => $challenge['salt'],
                    'signature' => $challenge['signature'],
                    'took' => 1,
                ];
                if ($tamper) {
                    $payload = $tamper($payload);
                }
                return base64_encode(json_encode($payload));
            }
        }

        $this->fail('Challenge could not be solved');
    }

    private function freshPayload(): string
    {
        return $this->solve($this->getJson('/api/altcha/challenge')->assertOk()->json());
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['admin' => false, 'active' => true, 'is_guest' => false])->save();

        return $user;
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(app(Altcha::class)->isEnabled());
        $this->getJson('/api/altcha/challenge')->assertNotFound();

        $user = $this->makeUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_challenge_format(): void
    {
        $this->enableAltcha(5000);

        $challenge = $this->getJson('/api/altcha/challenge')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json();

        $this->assertSame('SHA-256', $challenge['algorithm']);
        $this->assertSame(5000, $challenge['maxnumber']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}\?expires=\d+$/', $challenge['salt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['challenge']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['signature']);
    }

    public function test_login_requires_a_valid_solution_when_enabled(): void
    {
        $user = $this->makeUser();
        $this->enableAltcha();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJson(['code' => 'altcha_invalid']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'altcha' => $this->freshPayload(),
        ])->assertOk();
    }

    public function test_a_solution_can_only_be_used_once(): void
    {
        $user = $this->makeUser();
        $this->enableAltcha();
        $payload = $this->freshPayload();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password', 'altcha' => $payload])
            ->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password', 'altcha' => $payload])
            ->assertStatus(422);
    }

    public function test_tampered_or_forged_solutions_are_rejected(): void
    {
        $this->enableAltcha();
        $altcha = app(Altcha::class);
        $challenge = $altcha->createChallenge();

        // wrong number
        $this->assertFalse($altcha->verify($this->solve($challenge, fn ($p) => ['number' => $p['number'] + 1] + $p)));
        // forged challenge signed with another key
        $forged = $challenge;
        $forged['signature'] = hash_hmac('sha256', $challenge['challenge'], 'not-the-key');
        $this->assertFalse($altcha->verify($this->solve($forged)));
        // longer expiry written into the salt
        $this->assertFalse($altcha->verify($this->solve($challenge, fn ($p) => ['salt' => preg_replace('/expires=\d+/', 'expires=9999999999', $p['salt'])] + $p)));
        // other algorithm
        $this->assertFalse($altcha->verify($this->solve($challenge, fn ($p) => ['algorithm' => 'SHA-1'] + $p)));
        // garbage
        $this->assertFalse($altcha->verify('not base64 json'));
        $this->assertFalse($altcha->verify(null));

        // the untouched solution still works
        $this->assertTrue($altcha->verify($this->solve($challenge)));
    }

    public function test_expired_solutions_are_rejected(): void
    {
        $this->enableAltcha();
        $altcha = app(Altcha::class);

        $challenge = $altcha->createChallenge(time() - Altcha::TTL - 5);

        $this->assertFalse($altcha->verify($this->solve($challenge)));
    }

    public function test_forgot_password_and_registration_are_protected(): void
    {
        $this->enableAltcha();

        $this->postJson('/api/auth/forgot-password', ['email' => 'someone@example.com'])
            ->assertStatus(422)->assertJson(['code' => 'altcha_invalid']);

        $this->postJson('/api/auth/register', [
            'name' => 'New', 'email' => 'new@example.com',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ])->assertStatus(422)->assertJson(['code' => 'altcha_invalid']);
    }

    public function test_difficulty_is_clamped(): void
    {
        $this->enableAltcha(5);
        $this->assertSame(Altcha::MIN_MAX_NUMBER, app(Altcha::class)->maxNumber());

        $this->enableAltcha(999999999);
        $this->assertSame(Altcha::MAX_MAX_NUMBER, app(Altcha::class)->maxNumber());
    }

    public function test_share_password_guessing_is_throttled(): void
    {
        $owner = $this->makeUser();
        $share = new Share();
        $share->forceFill([
            'user_id' => $owner->id,
            'name' => 'Protected',
            'path' => 'test/protected',
            'password' => Hash::make('right-password'),
            'long_id' => 'quiet-cloud-shrill-thunder',
            'size' => 0,
            'file_count' => 0,
            'expires_at' => now()->addDay(),
        ])->save();

        for ($i = 0; $i < 10; $i++) {
            $this->post("/api/shares/{$share->long_id}/download", ['password' => 'wrong-' . $i])
                ->assertRedirect("/shares/{$share->long_id}?error=invalid_password");
        }

        // even the right password is refused until the window passes
        $this->post("/api/shares/{$share->long_id}/download", ['password' => 'right-password'])
            ->assertRedirect("/shares/{$share->long_id}?error=too_many_attempts");

        $this->postJson("/api/shares/{$share->long_id}/download/file/a.txt", ['password' => 'right-password'])
            ->assertStatus(429);

        // another client is not affected
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post("/api/shares/{$share->long_id}/download", ['password' => 'wrong'])
            ->assertRedirect("/shares/{$share->long_id}?error=invalid_password");
    }
}
