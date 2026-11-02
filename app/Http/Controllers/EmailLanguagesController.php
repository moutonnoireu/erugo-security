<?php

namespace App\Http\Controllers;

use App\Services\EmailTranslator;
use Illuminate\Http\Request;

/**
 * Languages of the e-mails. Shipped languages (lang/emails) are read-only; edits and new
 * languages are written to storage/templates/lang and take precedence over them.
 */
class EmailLanguagesController extends Controller
{
  public function index()
  {
    $english = EmailTranslator::lines(EmailTranslator::FALLBACK);
    $keys = array_values(array_filter(array_keys($english), fn($k) => $k !== '_meta.name'));

    $languages = [];
    foreach (EmailTranslator::languages() as $code => $name) {
      $languages[] = [
        'code' => $code,
        'name' => $name,
        'shipped' => is_file(EmailTranslator::shippedPath($code)),
        'custom' => is_file(EmailTranslator::customPath($code)),
        'lines' => EmailTranslator::lines($code),
        'missing' => count(array_filter($keys, fn($k) => !array_key_exists($k, $this->own($code)))),
      ];
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Email languages fetched successfully',
      'data' => [
        'languages' => $languages,
        'reference' => $english,
        'keys' => $keys,
        'active' => EmailTranslator::locale(),
      ],
    ]);
  }

  /** Strings a language defines itself, without English filling the gaps. */
  private function own(string $code): array
  {
    $read = function (string $path) {
      $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
      return is_array($data) ? $data : [];
    };
    return array_merge($read(EmailTranslator::shippedPath($code)), $read(EmailTranslator::customPath($code)));
  }

  public function store(Request $request)
  {
    $code = strtolower(trim((string) $request->input('code')));
    $name = trim((string) $request->input('name'));

    if (!EmailTranslator::isValidCode($code)) {
      return $this->error('Invalid language code. Use a code such as "es" or "pt-BR".', 422);
    }
    if ($name === '' || mb_strlen($name) > 40) {
      return $this->error('A language name of 1 to 40 characters is required.', 422);
    }
    if (EmailTranslator::exists($code)) {
      return $this->error('This language already exists.', 409);
    }

    $this->write($code, ['_meta.name' => $name]);
    return $this->index();
  }

  public function update(Request $request, string $code)
  {
    if (!EmailTranslator::isValidCode($code) || !EmailTranslator::exists($code)) {
      return $this->error('Language not found.', 404);
    }

    $english = EmailTranslator::lines(EmailTranslator::FALLBACK);
    $incoming = $request->input('lines');
    if (!is_array($incoming)) {
      return $this->error('Invalid payload.', 422);
    }

    // Only the keys the admin touched are sent; an empty value resets that key to the shipped text.
    $custom = EmailTranslator::customLines($code);
    foreach ($incoming as $key => $value) {
      // Laravel turns empty strings into null; both mean "reset this text".
      if (!is_string($key) || !array_key_exists($key, $english) || !(is_string($value) || $value === null)) {
        continue;
      }
      $value = trim((string) $value);
      if ($value === '') {
        unset($custom[$key]);
        continue;
      }
      if (mb_strlen($value) > 500) {
        return $this->error("The text for \"$key\" is too long (500 characters at most).", 422);
      }
      // A translation that drops a placeholder of the English text would lose information.
      foreach ($this->placeholders($english[$key]) as $placeholder) {
        if ($this->requiresPlaceholder($key) && !str_contains($value, $placeholder)) {
          return $this->error("The text for \"$key\" must contain $placeholder.", 422);
        }
      }
      $custom[$key] = $value;
    }

    $this->write($code, $custom);
    return $this->index();
  }

  public function destroy(string $code)
  {
    if (!EmailTranslator::isValidCode($code) || !is_file(EmailTranslator::customPath($code))) {
      return $this->error('Language not found.', 404);
    }
    if ($code === EmailTranslator::FALLBACK || is_file(EmailTranslator::shippedPath($code))) {
      // Shipped languages cannot be removed; only the admin's edits are reset.
      @unlink(EmailTranslator::customPath($code));
    } else {
      if ($code === EmailTranslator::locale()) {
        return $this->error('This language is the language of the instance and cannot be deleted.', 409);
      }
      @unlink(EmailTranslator::customPath($code));
    }
    EmailTranslator::flush();
    return $this->index();
  }

  private function placeholders(string $text): array
  {
    preg_match_all('/\{[a-z_]+\}/', $text, $m);
    return array_unique($m[0]);
  }

  /** Counts vary by language (one/other forms), so {count} is optional; the others are not. */
  private function requiresPlaceholder(string $key): bool
  {
    return !str_ends_with($key, '_one') && !str_ends_with($key, '_other');
  }

  private function write(string $code, array $lines): void
  {
    $path = EmailTranslator::customPath($code);
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    EmailTranslator::flush();
  }

  private function error(string $message, int $status)
  {
    return response()->json(['status' => 'error', 'message' => $message, 'data' => []], $status);
  }
}
