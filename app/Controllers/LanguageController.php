<?php

namespace App\Controllers;

use App\Filters\LocaleFilter;
use Config\App as AppConfig;

/**
 * Change la langue d'affichage (cookie mymemo_locale, voir LocaleFilter).
 * N'authentifie ni n'autorise rien : accessible aussi bien depuis la page de
 * login (visiteur non authentifié) que depuis n'importe quel écran protégé.
 */
final class LanguageController extends BaseController
{
    public function switch(string $locale)
    {
        $supported = config(AppConfig::class)->supportedLocales;
        $redirect = redirect()->back();

        // redirect() construit sa propre réponse : un cookie posé sur
        // $this->response séparément n'atterrirait jamais dans la réponse
        // réellement envoyée. Il doit être chaîné ici.
        if (in_array($locale, $supported, true)) {
            $redirect = $redirect->setCookie(LocaleFilter::COOKIE_NAME, $locale, 31536000);
        }

        return $redirect;
    }
}
