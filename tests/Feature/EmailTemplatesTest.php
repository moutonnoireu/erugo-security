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
            'invite_url' => 'https://partage.example.test/?invite_token=tok123',
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

    public function test_logo_and_plate_are_flattened_into_one_image(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available');
        }
        $this->setSetting('application_url', 'https://partage.example.test');
        $this->setSetting('email_logo_background', '#112233');
        $image = imagecreatetruecolor(400, 100);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('images/email-logo.png', (string) ob_get_clean());

        $html = $this->render('shareDownloadedMail');

        $this->assertMatchesRegularExpression('/<img src="https:\/\/partage\.example\.test\/images\/email-logo-plate-[0-9a-f]{12}\.png" alt="Cabinet Exemple" width="184" height="56"/', $html);
        $files = array_values(array_filter(Storage::disk('public')->files('images'), fn($f) => str_contains($f, 'email-logo-plate-')));
        $this->assertCount(1, $files);
        $flat = imagecreatefromstring(Storage::disk('public')->get($files[0]));
        $this->assertSame([460, 140], [imagesx($flat), imagesy($flat)]);
        // A transparent logo shows the plate colour, opaque
        $this->assertSame(0x112233, imagecolorat($flat, 200, 70) & 0xFFFFFFFF);

        // A new colour replaces the previous image
        $this->setSetting('email_logo_background', '#ffffff');
        $this->render('shareDownloadedMail');
        $this->assertCount(1, array_filter(Storage::disk('public')->files('images'), fn($f) => str_contains($f, 'email-logo-plate-')));
        $this->assertFalse(Storage::disk('public')->exists($files[0]));
    }

    private function previewRequest(array $variables, string $id = 'shareCreatedMail', ?User $user = null)
    {
        return $this->postJson('/api/email-templates/preview', ['id' => $id, 'variables' => $variables], $this->bearer($user ?? $this->admin()));
    }

    public function test_footer_replaces_the_automatic_line(): void
    {
        $default = $this->previewRequest(['header' => 'Titre', 'content' => '<p>Corps</p>', 'action_text' => 'Ouvrir'])
            ->assertOk()->json('data.html');
        $this->assertStringContainsString('This is an automatic message', $default);

        $html = $this->previewRequest([
            'header' => 'Titre', 'content' => '<p>Corps</p>', 'action_text' => 'Ouvrir',
            'footer' => '<p>Envoyé par <a href="https://example.test">{{ brand.name }}</a></p>',
        ])->assertOk()->json('data.html');
        $this->assertStringContainsString('Envoyé par <a href="https://example.test">Cabinet Exemple</a>', $html);
        $this->assertStringNotContainsString('This is an automatic message', $html);
        $this->assertGreaterThan(strpos($html, 'Ouvrir</a>'), strpos($html, 'Envoyé par'));
        $this->assertStringNotContainsString('<style', $html);
    }

    public function test_footer_is_saved_listed_and_used_by_the_real_mail(): void
    {
        $headers = $this->bearer($this->admin());
        $template = collect($this->getJson('/api/email-templates', $headers)->json('data.templates'))->firstWhere('id', 'shareExpiredWarningMail');
        $template['variables']['footer'] = '<p>Pied personnalisé</p>';

        $this->putJson('/api/email-templates', [$template], $headers)->assertOk();

        $saved = collect($this->getJson('/api/email-templates', $headers)->json('data.templates'))->firstWhere('id', 'shareExpiredWarningMail');
        $this->assertSame('<p>Pied personnalisé</p>', trim($saved['variables']['footer']));
        $this->assertNotContains('footer', $saved['requiredVariables']);
        app('view')->getFinder()->flush();
        $html = $this->render('shareExpiredWarningMail');
        $this->assertStringContainsString('Pied personnalisé', $html);
        $this->assertStringNotContainsString('This is an automatic message', $html);

        $saved['variables']['footer'] = '';
        $this->putJson('/api/email-templates', [$saved], $headers)->assertOk();
        $cleared = collect($this->getJson('/api/email-templates', $headers)->json('data.templates'))->firstWhere('id', 'shareExpiredWarningMail');
        $this->assertArrayNotHasKey('footer', $cleared['variables']);
    }

    public function test_every_shipped_template_can_be_previewed(): void
    {
        $headers = $this->bearer($this->admin());

        foreach ($this->getJson('/api/email-templates', $headers)->json('data.templates') as $template) {
            $response = $this->postJson('/api/email-templates/preview', [
                'id' => $template['id'], 'variables' => $template['variables'],
            ], $headers);
            $this->assertSame(200, $response->status(), $template['id'] . ': ' . $response->json('message'));
        }
    }

    /** @dataProvider forbiddenTwig */
    public function test_preview_is_sandboxed(string $payload): void
    {
        $this->previewRequest(['header' => 'T', 'content' => $payload])->assertStatus(422);
    }

    public static function forbiddenTwig(): array
    {
        return [
            'laravel config' => ['{{ config_get("app.key") }}'],
            'config function' => ['{{ config("app.key") }}'],
            'facade' => ['{{ Auth.user() }}{{ app() }}'],
            'callable filter' => ['{{ ["id"]|map("system")|join }}'],
            'include' => ['{% include "emails/layout" %}'],
            'include function' => ['{{ include("emails/layout") }}'],
            'source' => ['{{ source("emails/layout") }}'],
            'constant' => ['{{ constant("PHP_VERSION") }}'],
            'syntax error' => ['{% if %}'],
        ];
    }

    public function test_preview_is_admin_only_and_checks_the_template(): void
    {
        $this->postJson('/api/email-templates/preview', [])->assertStatus(401);
        $user = User::factory()->create();
        $user->forceFill(['admin' => false, 'active' => true, 'is_guest' => false])->save();
        $this->previewRequest(['header' => 'T'], 'shareCreatedMail', $user)->assertStatus(403);
        Auth::forgetGuards();
        $this->previewRequest(['header' => 'T'], 'layout')->assertStatus(404);
        $this->previewRequest(['header' => 'T'], '../layout')->assertStatus(422);
    }

    private function fakeSmtp(): void
    {
        $this->mock(\App\Services\EmailConfigService::class, function ($mock) {
            $mock->shouldReceive('configureMailer')->andReturnUsing(fn() => app('mail.manager')->mailer('array'));
            $mock->shouldReceive('configureMailable')->andReturnUsing(fn($mailable) => $mailable->from('partage@example.test'));
        });
    }

    public function test_test_mail_goes_to_the_signed_in_admin_only(): void
    {
        $this->fakeSmtp();
        $admin = $this->admin();

        $this->postJson('/api/email-templates/test', [
            'id' => 'shareCreatedMail', 'subject' => 'Sujet', 'to' => 'other@example.test',
            'variables' => ['header' => 'Titre', 'content' => '<p>Brouillon</p>', 'action_text' => 'Ouvrir'],
        ], $this->bearer($admin))->assertOk()->assertJsonPath('data.sent_to', $admin->email);

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0]->getOriginalMessage();
        $this->assertSame([$admin->email], array_map(fn($a) => $a->getAddress(), $message->getTo()));
        $this->assertSame('[Test] Sujet', $message->getSubject());
        $this->assertStringContainsString('<p>Brouillon</p>', $message->getHtmlBody());
        // The real mail keeps its <style> element, unlike the in-app preview
        $this->assertStringContainsString('<style', $message->getHtmlBody());
    }

    public function test_test_mail_is_admin_only_and_sandboxed(): void
    {
        $this->fakeSmtp();
        $draft = ['id' => 'shareCreatedMail', 'subject' => 'S', 'variables' => ['content' => '{{ config("app.key") }}']];

        $this->postJson('/api/email-templates/test', $draft)->assertStatus(401);
        $this->postJson('/api/email-templates/test', $draft, $this->bearer($this->admin()))->assertStatus(422);
        $user = User::factory()->create();
        $user->forceFill(['admin' => false, 'active' => true, 'is_guest' => false])->save();
        Auth::forgetGuards();
        $this->postJson('/api/email-templates/test', $draft, $this->bearer($user))->assertStatus(403);
        $this->assertCount(0, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_special_characters_are_escaped_once(): void
    {
        $this->setSetting('application_name', 'Dupont & Associés');

        $html = $this->render('shareExpiredWarningMail');
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertMatchesRegularExpression('/>Open Dupont &amp; Associés<\/a>/', $html);

        // Same layout in the sandbox of the preview, with markup in a value kept inert
        $this->setSetting('application_name', '<b>X</b> & Y');
        $preview = $this->previewRequest([
            'header' => "{{ settings.application_name }}", 'content' => '<p>c</p>',
            'action_text' => "{{ t('share_expired.button', {app: settings.application_name}) }}",
        ])->assertOk()->json('data.html');
        $this->assertStringNotContainsString('&amp;amp;', $preview);
        $this->assertStringNotContainsString('<b>X</b>', $preview);
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
