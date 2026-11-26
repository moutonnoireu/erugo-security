<?php

namespace App\Services;

use App\Twig\EmailExtension;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders an unsaved e-mail template with sample data, for the template editor.
 *
 * The template text comes from the request, so it runs in its own sandboxed Twig environment:
 * no Laravel functions or facades, a short allow list of tags, filters and functions, and
 * sample data made of arrays only (no object to call methods on).
 */
class EmailPreview
{
    private const TAGS = ['extends', 'block', 'if', 'for', 'set'];
    private const FILTERS = ['capitalize', 'date', 'default', 'e', 'escape', 'join', 'length', 'lower', 'nl2br', 'raw', 'striptags', 'title', 'trim', 'upper'];
    private const FUNCTIONS = ['block', 'parent', 't'];

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * @param array<string, ?string> $blocks header, content, action_url, action_text, footer
     * @param bool $forBrowser strip <style> for the in-app preview; keep it for a test e-mail
     * @throws \Twig\Error\Error when the template is invalid or uses something not allowed
     */
    public function render(array $blocks, bool $forBrowser = true): string
    {
        $template = "{% extends 'emails/layout' %}\n";
        foreach (['header', 'content', 'action_url', 'action_text', 'footer'] as $name) {
            if (($blocks[$name] ?? '') !== '') {
                $template .= '{% block ' . $name . ' %}' . $blocks[$name] . "{% endblock %}\n";
            }
        }

        $twig = new Environment(new ArrayLoader([
            'emails/layout' => $this->layout(),
            'preview' => $template,
        ]), ['autoescape' => 'html', 'cache' => false]);
        $twig->addExtension(new EmailExtension());

        // The layout is ours and goes through the same sandbox as the edited blocks.
        $twig->addExtension(new SandboxExtension(
            new SecurityPolicy(self::TAGS, self::FILTERS, [], [], self::FUNCTIONS),
            true
        ));

        $html = $twig->render('preview', $this->sampleData());

        if (!$forBrowser) {
            return $html;
        }

        // The preview is shown under the application's CSP, which refuses <style> elements.
        return (string) preg_replace('/<style\b.*?<\/style>/is', '', $html);
    }

    private function layout(): string
    {
        $custom = storage_path('templates/emails/layout.twig');

        return (string) file_get_contents(is_file($custom) ? $custom : resource_path('views/emails/layout.twig'));
    }

    private function sampleData(): array
    {
        $user = ['name' => 'Jean Martin', 'first_name' => 'Jean', 'email' => 'jean.martin@example.com'];

        return [
            'brand' => EmailBrand::toArray(),
            'settings' => $this->settings->getGlobalViewData(),
            'user' => $user,
            'share' => [
                'name' => 'Dossier exemple',
                'long_id' => 'exemple-de-partage',
                'file_count' => 3,
                'download_count' => 1,
                'user' => $user,
            ],
            'invite' => ['id' => 1, 'message' => 'Message d’exemple.'],
            'recipient_name' => 'Jean',
            'sender_name' => 'Marie',
            'isExistingUser' => false,
            'invite_url' => rtrim((string) $this->settings->get('application_url'), '/') . '/?invite_token=exemple',
            'token' => 'exemple',
            'code' => '123456',
            'share_expires_in' => '3 jours',
            'deletes_in' => '3 jours',
        ];
    }
}
