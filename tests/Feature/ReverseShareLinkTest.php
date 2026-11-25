<?php

namespace Tests\Feature;

use App\Models\ReverseShareInvite;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReverseShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['admin' => false, 'active' => true, 'is_guest' => false])->save();

        return $user;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . Auth::guard('api')->login($user)];
    }

    private function setSetting(string $key, string $value): void
    {
        Setting::where('key', $key)->update(['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['invite_token'];
    }

    public function test_creates_a_link_without_sending_any_mail(): void
    {
        Mail::fake();
        Queue::fake();
        $this->setSetting('application_url', 'https://partage.example.test/');
        $owner = $this->user();

        $response = $this->postJson('/api/reverse-shares/link', [
            'recipient_name' => 'Marie Martin',
            'message' => 'Merci de déposer le dossier.',
            'expiry_days' => 10,
        ], $this->bearer($owner))->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.invite.recipient_name', 'Marie Martin')
            ->assertJsonPath('data.invite.message', 'Merci de déposer le dossier.');

        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://partage.example.test/?invite_token=', $url);

        // The token is decryptable and is the JWT of the guest user
        $jwt = Crypt::decryptString($this->tokenFromUrl($url));
        $invite = ReverseShareInvite::firstOrFail();
        $this->assertSame($owner->id, $invite->user_id);
        $guest = User::findOrFail($invite->guest_user_id);
        $this->assertTrue((bool) $guest->is_guest);
        $this->assertSame((string) $guest->id, json_decode(base64_decode(explode('.', $jwt)[1]), true)['sub']);

        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $invite->expires_at->timestamp, 5);
        $this->assertSame($invite->expires_at->utc()->format('Y-m-d\TH:i:s\Z'), $response->json('data.expires_at'));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_defaults(): void
    {
        $this->postJson('/api/reverse-shares/link', [], $this->bearer($this->user()))
            ->assertStatus(201)
            ->assertJsonPath('data.invite.recipient_name', 'Invité');

        $invite = ReverseShareInvite::firstOrFail();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invite->expires_at->timestamp, 5);
    }

    public function test_guest_token_lives_as_long_as_the_invite(): void
    {
        $url = $this->postJson('/api/reverse-shares/link', ['expiry_days' => 30], $this->bearer($this->user()))
            ->assertStatus(201)->json('data.url');

        $payload = json_decode(base64_decode(explode('.', Crypt::decryptString($this->tokenFromUrl($url)))[1]), true);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $payload['exp'], 10);

        // The TTL of the guard is back to its configured value afterwards
        $this->assertSame((int) config('jwt.ttl'), auth()->factory()->getTTL());
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/reverse-shares/link', [])->assertStatus(401);
        $this->assertSame(0, ReverseShareInvite::count());
    }

    public function test_refused_when_reverse_shares_are_not_allowed(): void
    {
        $this->setSetting('allow_reverse_shares', 'false');

        $this->postJson('/api/reverse-shares/link', [], $this->bearer($this->user()))
            ->assertStatus(400)
            ->assertExactJson(['status' => 'error', 'message' => 'Reverse shares are not allowed']);
        $this->assertSame(0, ReverseShareInvite::count());
    }

    public function test_missing_setting_does_not_crash(): void
    {
        Setting::where('key', 'allow_reverse_shares')->delete();
        app(SettingsService::class)->clearCache();
        $owner = $this->user();

        $this->postJson('/api/reverse-shares/link', [], $this->bearer($owner))->assertStatus(400);
        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'A', 'recipient_email' => 'a@example.test',
        ], $this->bearer($owner))->assertStatus(400);
    }

    /** @dataProvider invalidPayloads */
    public function test_validation(array $payload, string $field): void
    {
        $this->postJson('/api/reverse-shares/link', $payload, $this->bearer($this->user()))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['data' => ['errors' => [$field]]]);
        $this->assertSame(0, ReverseShareInvite::count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'expiry too low' => [['expiry_days' => 0], 'expiry_days'],
            'expiry too high' => [['expiry_days' => 31], 'expiry_days'],
            'expiry not an integer' => [['expiry_days' => 'abc'], 'expiry_days'],
            'name too long' => [['recipient_name' => str_repeat('a', 256)], 'recipient_name'],
            'message too long' => [['message' => str_repeat('a', 1001)], 'message'],
        ];
    }

    public function test_returned_url_is_accepted(): void
    {
        $url = $this->postJson('/api/reverse-shares/link', [], $this->bearer($this->user()))
            ->assertStatus(201)->json('data.url');

        // Fresh request: the guard must not keep the owner logged in by bearer()
        Auth::forgetGuards();

        $this->getJson('/api/reverse-shares/accept?token=' . rawurlencode($this->tokenFromUrl($url)))
            ->assertOk();

        $this->assertTrue(ReverseShareInvite::firstOrFail()->isUsed());
    }

    public function test_invite_mail_url_is_built_by_the_shared_method(): void
    {
        $this->setSetting('application_url', 'https://partage.example.test');
        $owner = $this->user();
        $invite = ReverseShareInvite::create([
            'user_id' => $owner->id, 'guest_user_id' => null, 'recipient_name' => 'A B',
            'recipient_email' => 'a@example.test', 'message' => null, 'expires_at' => now()->addDay(),
        ]);

        $guest = (new \App\Mail\reverseShareInviteMail($owner, $invite, 'a+b/c=', false))->invite_url;
        $existing = (new \App\Mail\reverseShareInviteMail($owner, $invite, null, true))->invite_url;

        $this->assertSame('https://partage.example.test/?invite_token=a%2Bb%2Fc%3D', $guest);
        $this->assertSame('https://partage.example.test/?invite_id=' . $invite->id, $existing);
    }
}
