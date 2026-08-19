<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App as AppConfig;

/**
 * Applique la préférence de langue de l'utilisateur (cookie mymemo_locale,
 * pas la session : doit survivre à session()->destroy() sur déconnexion, et
 * fonctionner pour un visiteur non authentifié sur la page de login — voir
 * docs du plan). Ne modifie jamais Config\App::$defaultLocale lui-même,
 * seulement la locale de la requête courante (CodeIgniter natif).
 */
final class LocaleFilter implements FilterInterface
{
    public const COOKIE_NAME = 'mymemo_locale';

    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config(AppConfig::class);
        $locale = (string) ($request->getCookie(self::COOKIE_NAME) ?? '');

        if (! in_array($locale, $config->supportedLocales, true)) {
            $locale = $config->defaultLocale;
        }

        $request->setLocale($locale);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
