<?php

namespace App\Twig;

use App\Services\EmailTranslator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Adds t('key', {param: value}) to e-mail templates. Output is escaped by EmailTranslator. */
class EmailExtension extends AbstractExtension
{
  public function getFunctions(): array
  {
    return [
      new TwigFunction('t', fn(string $key, array $params = []) => EmailTranslator::t($key, $params), ['is_safe' => ['html']]),
    ];
  }
}
