<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/** Identity of the instance as the e-mail layout needs it: name, logo on its plate, accent colour. */
class EmailBrand
{
  public const LOGO_FILES = ['images/email-logo.png', 'images/email-logo.jpg'];
  public const DEFAULT_PLATE = '#ffffff';
  public const DEFAULT_ACCENT = '#1f5a6e';
  private const FLAT_PREFIX = 'email-logo-plate-';
  /** Displayed size of the logo with its plate: height, and maximum width (column minus padding). */
  private const LOGO_HEIGHT = 56;
  private const LOGO_MAX_WIDTH = 400;

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

  /**
   * The logo drawn on its plate, as one PNG. Mail clients in dark mode (Outlook) invert CSS
   * backgrounds but not images, which made a light logo on a dark plate unreadable.
   * Returns null when the image cannot be processed; the layout then uses a CSS plate.
   *
   * @return array{path: string, width: int, height: int}|null
   */
  public static function platedLogo(string $logo, string $plate): ?array
  {
    $disk = Storage::disk('public');
    $path = 'images/' . self::FLAT_PREFIX . substr(md5($logo . $disk->lastModified($logo) . $plate), 0, 12) . '.png';

    if (!$disk->exists($path)) {
      $source = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) $disk->get($logo)) : false;
      if (!$source) {
        return null;
      }
      [$w, $h] = [imagesx($source), imagesy($source)];
      // Same proportions as an 8px/12px plate around a 40px logo
      [$padX, $padY] = [(int) round($h * 0.3), (int) round($h * 0.2)];

      $canvas = imagecreatetruecolor($w + 2 * $padX, $h + 2 * $padY);
      [$r, $g, $b] = array_map('hexdec', str_split(substr($plate, 1), 2));
      imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
      imagealphablending($canvas, true);
      imagecopy($canvas, $source, $padX, $padY, 0, 0, $w, $h);

      ob_start();
      imagepng($canvas);
      $png = (string) ob_get_clean();

      self::deletePlatedLogos();
      $disk->put($path, $png);
    }

    $size = getimagesizefromstring((string) $disk->get($path));
    $scale = min(self::LOGO_HEIGHT / $size[1], self::LOGO_MAX_WIDTH / $size[0]);

    return ['path' => $path, 'width' => (int) round($size[0] * $scale), 'height' => (int) round($size[1] * $scale)];
  }

  public static function deletePlatedLogos(): void
  {
    $disk = Storage::disk('public');
    foreach ($disk->files('images') as $file) {
      if (str_starts_with(basename($file), self::FLAT_PREFIX)) {
        $disk->delete($file);
      }
    }
  }

  public static function toArray(): array
  {
    $settings = app(SettingsService::class);
    $url = rtrim((string) $settings->get('application_url'), '/');
    $accent = self::hex($settings->get('css_primary_color'), self::DEFAULT_ACCENT);
    $logo = self::logoPath();
    $plate = self::hex($settings->get('email_logo_background'), self::DEFAULT_PLATE);

    $plated = $logo && $url !== '' ? self::platedLogo($logo, $plate) : null;

    return [
      'plated_logo' => $plated ? ['url' => $url . '/' . $plated['path'], 'width' => $plated['width'], 'height' => $plated['height']] : null,
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
