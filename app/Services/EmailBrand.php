<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/** Identity of the instance as the e-mail layout needs it: name, logo on its plate, accent colour. */
class EmailBrand
{
  public const LOGO_FILES = ['images/email-logo.png', 'images/email-logo.jpg'];
  public const DEFAULT_PLATE = '#ffffff';
  public const DEFAULT_ACCENT = '#1f5a6e';

  public static function hex(?string $value, string $default): string
  {
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default;
  }

  private static function luminance(string $hex): float
  {
    $channels = array_map(function ($i) use ($hex) {
      $c = hexdec(substr($hex, $i, 2)) / 255;
      return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }, [1, 3, 5]);
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  }

  public static function contrast(string $a, string $b): float
  {
    [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];
    return ($hi + 0.05) / ($lo + 0.05);
  }

  /** Text colour for a given background: whichever of white and near-black reads better. */
  public static function inkOn(string $background): string
  {
    return self::contrast($background, '#ffffff') >= self::contrast($background, '#14201d') ? '#ffffff' : '#14201d';
  }

  /** Path of the uploaded e-mail logo on the public disk, or null when there is none. */
  public static function logoPath(): ?string
  {
    foreach (self::LOGO_FILES as $path) {
      if (Storage::disk('public')->exists($path)) {
        return $path;
      }
    }
    return null;
  }

  public static function toArray(): array
  {
    $settings = app(SettingsService::class);
    $url = rtrim((string) $settings->get('application_url'), '/');
    $accent = self::hex($settings->get('css_primary_color'), self::DEFAULT_ACCENT);
    $logo = self::logoPath();
    $plate = self::hex($settings->get('email_logo_background'), self::DEFAULT_PLATE);

    return [
      'name' => (string) $settings->get('application_name'),
      'url' => $url,
      'logo_url' => $logo && $url !== '' ? $url . '/' . $logo . '?v=' . Storage::disk('public')->lastModified($logo) : null,
      'plate' => $plate,
      'plate_ink' => self::inkOn($plate),
      'accent' => $accent,
      'accent_ink' => self::inkOn($accent),
      'lang' => EmailTranslator::locale(),
    ];
  }
}
