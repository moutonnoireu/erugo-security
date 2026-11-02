<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\EmailTranslator;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(storage_path('templates'));
        Storage::fake('public');
        $this->setSetting('application_name', 'Cabinet Exemple');
        $this->setSetting('application_url', 'https://partage.example.test');
        $this->setSetting('default_language', 'en');
        EmailTranslator::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('templates'));
        EmailTranslator::flush();
        parent::tearDown();
    }

    private function setSetting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'system']);
        app(SettingsService::class)->clearCache();
        EmailTranslator::flush();
    }

    private function share(string $ownerName = 'Marie Dupont'): Share
    {
        $owner = new User(['name' => $ownerName, 'email' => 'owner@example.test']);
        $share = (new Share())->forceFill(['name' => 'Dossier Martin', 'long_id' => 'ABCD-1234', 'file_count' => 3, 'download_count' => 2]);
        $share->setRelation('user', $owner);
        return $share;
    }

    private function data(): array
    {
        $share = $this->share();
        return [
            'user' => $share->user,
            'share' => $share,
            'token' => 'tok123',
            'code' => '123456',
            'recipient_name' => 'Jean Martin',
            'sender_name' => 'Marie Dupont',
            'isExistingUser' => false,
            'invite' => (object) ['id' => 7, 'message' => 'Merci d’avance.'],
            'share_expires_in' => '3 days',
            'deletes_in' => '3 days',
        ];
    }

    private function render(string $view, array $extra = []): string
    {
        return view('emails.' . $view, array_merge($this->data(), $extra))->render();
    }

    public static function templates(): array
    {
        return array_map(fn($t) => [$t], [
            'accountCreatedMail', 'emailVerificationMail', 'passwordResetMail', 'reverseShareInviteMailV2',
            'shareCreatedMail', 'shareDownloadedMail', 'shareExpiryWarningMail', 'shareExpiredWarningMail',
            'shareDeletionWarningMail', 'shareDeletedWarningMail',
        ]);
    }

    /** @dataProvider templates */
    public function test_every_template_renders_in_both_shipped_languages(string $template): void
    {
        foreach (['en', 'fr'] as $lang) {
            $this->setSetting('default_language', $lang);
            $html = $this->render($template);

            $this->assertStringContainsString('Cabinet Exemple', $html);
            $this->assertStringContainsString('lang="' . $lang . '"', $html);
            // A key printed as-is means a missing translation.
            $this->assertDoesNotMatchRegularExpression('/\b[a-z_]+\.(title|body|button)\b/', strip_tags($html), "$template/$lang shows a raw key");
            $this->assertStringNotContainsString('{', preg_replace('/<style.*?<\/style>/s', '', $html), "$template/$lang left a placeholder");
        }
    }

    public function test_shipped_languages_define_the_same_keys(): void
    {
        $en = array_keys(json_decode(file_get_contents(base_path('lang/emails/en.json')), true));
        $fr = array_keys(json_decode(file_get_contents(base_path('lang/emails/fr.json')), true));
        $this->assertEqualsCanonicalizing($en, $fr);
    }

    public function test_french_is_formal(): void
    {
        $this->setSetting('default_language', 'fr');
        $html = $this->render('shareDownloadedMail');

        $this->assertStringContainsString('Bonjour Marie Dupont,', $html);
        $this->assertStringContainsString('Votre partage a été téléchargé', $html);
        $this->assertStringNotContainsString('!', strip_tags(preg_replace('/<style.*?<\/style>/s', '', $html)));
    }

    public function test_greeting_drops_the_name_when_unknown(): void
    {
        $html = view('emails.shareCreatedMail', array_merge($this->data(), ['recipient_name' => '']))->render();
        $this->assertStringContainsString('Hello,', $html);
    }

    public function test_plural_and_escaping(): void
    {
        // file_count is computed from the database on a real Share, so a plain object stands in.
        $share = fn(int $n) => (object) ['file_count' => $n, 'long_id' => 'ABCD-1234'];

        $html = $this->render('shareCreatedMail', ['sender_name' => 'A <b>&</b> B', 'share' => $share(3)]);
        $this->assertStringContainsString('shared 3 files with you', $html);
        $this->assertStringNotContainsString('<b>&</b>', $html);
        $this->assertStringContainsString('A &lt;b&gt;&amp;&lt;/b&gt; B', $html);

        $this->assertStringContainsString('shared 1 file with you', $this->render('shareCreatedMail', ['share' => $share(1)]));
    }

    public function test_layout_without_logo_shows_the_name_and_a_bulletproof_button(): void
    {
        $html = $this->render('passwordResetMail');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertMatchesRegularExpression('/<td[^>]*bgcolor="#[0-9a-f]{6}"[^>]*>\s*<a href="https:\/\/partage\.example\.test\/reset-password\/tok123"/', $html);
        // The link is also printed in clear for clients that block buttons.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'https://partage.example.test/reset-password/tok123'));
    }

    public function test_layout_with_logo_uses_an_absolute_url_and_the_plate_colour(): void
    {
        Storage::disk('public')->put('images/email-logo.png', 'x');
        $this->setSetting('email_logo_background', '#112233');
        $html = $this->render('passwordResetMail');

        $this->assertMatchesRegularExpression('/<img src="https:\/\/partage\.example\.test\/images\/email-logo\.png\?v=\d+" alt="Cabinet Exemple"/', $html);
        $this->assertStringContainsString('#112233', $html);
    }

    public function test_button_text_has_enough_contrast_on_any_accent(): void
    {
        foreach (['#589db6', '#01021c', '#ffffff', '#ffd400', '#7a1f1f'] as $accent) {
            $ink = \App\Services\EmailBrand::inkOn($accent);
            $this->assertGreaterThanOrEqual(4.5, \App\Services\EmailBrand::contrast($accent, $ink), "accent $accent");
        }
    }

    public function test_added_language_falls_back_to_english_per_key(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/email-languages', ['code' => 'es', 'name' => 'Español'], $this->bearer($admin))->assertOk();
        $this->putJson('/api/email-languages/es', ['lines' => ['greeting' => 'Hola {name},']], $this->bearer($admin))->assertOk();
        $this->setSetting('default_language', 'es');

        $html = $this->render('shareDownloadedMail');
        $this->assertStringContainsString('Hola Marie Dupont,', $html);
        $this->assertStringContainsString('has been downloaded', $html);
    }

    public function test_language_editor_validation(): void
    {
        $admin = $this->admin();
        $h = $this->bearer($admin);

        $this->postJson('/api/email-languages', ['code' => '../x', 'name' => 'X'], $h)->assertStatus(422);
        $this->postJson('/api/email-languages', ['code' => 'fr', 'name' => 'Français'], $h)->assertStatus(409);
        $this->postJson('/api/email-languages', ['code' => 'de', 'name' => 'Deutsch'], $h)->assertOk();
        $this->putJson('/api/email-languages/de', ['lines' => ['account_created.body' => 'Kein Platzhalter']], $h)
            ->assertStatus(422);
        $this->putJson('/api/email-languages/zz', ['lines' => []], $h)->assertStatus(404);

        // Resetting an edit on a shipped language restores the shipped text.
        $this->putJson('/api/email-languages/fr', ['lines' => ['share_created.button' => 'Voir']], $h)->assertOk();
        $this->assertSame('Voir', EmailTranslator::t('share_created.button', [], 'fr'));
        $this->putJson('/api/email-languages/fr', ['lines' => ['share_created.button' => '']], $h)->assertOk();
        $this->assertSame('Consulter les fichiers', EmailTranslator::t('share_created.button', [], 'fr'));
    }

    public function test_language_and_logo_endpoints_are_admin_only(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['admin' => false, 'active' => true, 'is_guest' => false])->save();
        $h = $this->bearer($user);

        $this->getJson('/api/email-languages', $h)->assertStatus(403);
        $this->postJson('/api/email-branding/logo', [], $h)->assertStatus(403);
    }

    public function test_logo_upload_accepts_png_only_in_size_limits(): void
    {
        $h = $this->bearer($this->admin());

        $this->postJson('/api/email-branding/logo', ['logo' => UploadedFile::fake()->create('l.svg', 1, 'image/svg+xml')], $h)->assertStatus(422);
        $this->postJson('/api/email-branding/logo', ['logo' => $this->png('big.png', 1300, 100)], $h)->assertStatus(422);

        $this->postJson('/api/email-branding/logo', ['logo' => $this->png('l.png', 400, 120)], $h)
            ->assertOk()
            ->assertJsonPath('data.logo_url', fn($url) => str_contains($url, '/images/email-logo.png'));
        Storage::disk('public')->assertExists('images/email-logo.png');

        $this->deleteJson('/api/email-branding/logo', [], $h)->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing('images/email-logo.png');

        $this->putJson('/api/email-branding/plate', ['plate' => 'red'], $h)->assertStatus(422);
        $this->putJson('/api/email-branding/plate', ['plate' => '#ABCDEF'], $h)->assertOk()->assertJsonPath('data.plate', '#abcdef');
    }

    public function test_subject_is_translated_unless_the_admin_changed_it(): void
    {
        $this->setSetting('default_language', 'fr');
        $this->assertSame('Votre partage a été téléchargé', EmailTranslator::subject('shareDownloadedMail'));

        Setting::where('key', 'email_subject_shareDownloadedMail.twig')->update(['value' => 'Accusé de téléchargement']);
        $this->assertSame('Accusé de téléchargement', EmailTranslator::subject('shareDownloadedMail'));
    }

    public function test_existing_overrides_keep_working(): void
    {
        // An override saved by the template editor before this change: English text, no t() calls.
        $path = storage_path('templates/emails/shareDownloadedMail.twig');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "{% extends 'emails/layout' %}\n{% block header %}Custom title{% endblock %}\n{% block content %}<p>Hi {{ share.user.name }}</p>{% endblock %}\n{% block action_url %}{{ settings.application_url }}/shares/{{ share.long_id }}{% endblock %}\n{% block action_text %}Open{% endblock %}\n");

        $html = $this->render('shareDownloadedMail');
        $this->assertStringContainsString('Custom title', $html);
        $this->assertStringContainsString('Hi Marie Dupont', $html);
        $this->assertStringContainsString('>Open</a>', $html);
    }

    /** A PNG header with the given size; enough for getimagesize and needs no GD. */
    private function png(string $name, int $w, int $h): UploadedFile
    {
        $chunk = fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $bytes = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IEND', '');
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['admin' => true, 'active' => true, 'is_guest' => false])->save();
        return $user;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . Auth::guard('api')->login($user)];
    }
}
