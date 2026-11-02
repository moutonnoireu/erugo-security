<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Texts of the e-mails, one JSON file per language.
 *
 * Shipped languages live in lang/emails/<code>.json. Languages created or edited from the
 * admin screen live in storage/templates/lang/<code>.json and take precedence. English is
 * always the fallback, so a partly translated language still sends complete e-mails.
 */
class EmailTranslator
{
  public const FALLBACK = 'en';

  private static array $cache = [];

  public static function shippedPath(string $code): string
  {
    return base_path('lang/emails/' . $code . '.json');
  }

  public static function customPath(string $code): string
  {
    return storage_path('templates/lang/' . $code . '.json');
  }

  public static function isValidCode(string $code): bool
  {
    return (bool) preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code);
  }

  public static function flush(): void
  {
    self::$cache = [];
  }

  /** Language of the instance, falling back to English when it has no file. */
  public static function locale(): string
  {
    $code = (string) (app(SettingsService::class)->get('default_language') ?? self::FALLBACK);
    $code = strtolower(trim($code));
    return self::isValidCode($code) && self::exists($code) ? $code : self::FALLBACK;
  }

  public static function exists(string $code): bool
  {
    return is_file(self::shippedPath($code)) || is_file(self::customPath($code));
  }

  private static function read(string $path): array
  {
    if (!is_file($path)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? array_filter($data, 'is_string') : [];
  }

  /** Strings the admin has changed or added for this language only. */
  public static function customLines(string $code): array
  {
    return self::isValidCode($code) ? self::read(self::customPath($code)) : [];
  }

  /** All strings of a language, English filling the gaps. */
  public static function lines(string $code): array
  {
    if (!isset(self::$cache[$code])) {
      $lines = self::read(self::shippedPath(self::FALLBACK));
      if ($code !== self::FALLBACK && self::isValidCode($code)) {
        $lines = array_merge($lines, self::read(self::shippedPath($code)), self::read(self::customPath($code)));
      } else {
        $lines = array_merge($lines, self::read(self::customPath(self::FALLBACK)));
      }
      self::$cache[$code] = $lines;
    }
    return self::$cache[$code];
  }

  /** Every available language as code => display name. */
  public static function languages(): array
  {
    $codes = [];
    foreach ([base_path('lang/emails'), storage_path('templates/lang')] as $dir) {
      foreach (glob($dir . '/*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        if (self::isValidCode($code)) {
          $codes[$code] = true;
        }
      }
    }
    $languages = [];
    foreach (array_keys($codes) as $code) {
      $languages[$code] = self::lines($code)['_meta.name'] ?? $code;
    }
    ksort($languages);
    return $languages;
  }

  /**
   * Translate a key. {name} placeholders are replaced with escaped values. A numeric `count`
   * parameter selects the `_one` or `_other` variant. `greeting` falls back to the anonymous
   * form when no name is known.
   */
  public static function t(string $key, array $params = [], ?string $code = null): string
  {
    $lines = self::lines($code ?? self::locale());

    if ($key === 'greeting' && trim((string) ($params['name'] ?? '')) === '') {
      $key = 'greeting_anonymous';
    }
    if (isset($params['count']) && is_numeric($params['count'])) {
      $variant = $key . ((int) $params['count'] === 1 ? '_one' : '_other');
      if (isset($lines[$variant])) {
        $key = $variant;
      }
    }

    $text = $lines[$key] ?? $key;
    $replace = [];
    foreach ($params as $name => $value) {
      $replace['{' . $name . '}'] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    return strtr(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), $replace);
  }

  /** Subject of a message: an admin-edited subject wins, otherwise the translated default. */
  public static function subject(string $name): string
  {
    $lines = self::lines(self::locale());
    $english = self::lines(self::FALLBACK)['subject.' . $name] ?? '';
    $stored = Setting::where('key', 'email_subject_' . $name . '.twig')->value('value');

    $legacyDefaults = [$english, self::seededSubject($name)];
    if ($stored !== null && $stored !== '' && !in_array($stored, $legacyDefaults, true)) {
      return $stored;
    }
    return $lines['subject.' . $name] ?? ($stored ?: $name);
  }

  /** English subjects shipped by earlier versions of the seeder. */
  private static function seededSubject(string $name): string
  {
    return [
      'accountCreatedMail' => 'Account Created! Set your password',
      'emailVerificationMail' => 'Verify your email address',
      'passwordResetMail' => 'Reset your password',
      'reverseShareInviteMail' => 'You have been invited to share files',
      'shareCreatedMail' => 'Files have been shared with you',
      'shareDownloadedMail' => 'Your share has been downloaded',
      'shareExpiryWarningMail' => 'Your share will expire soon',
      'shareExpiredWarningMail' => 'Your share has expired',
      'shareDeletionWarningMail' => 'Your share will be deleted soon',
      'shareDeletedWarningMail' => 'Your share has been deleted',
    ][$name] ?? '';
  }
}
