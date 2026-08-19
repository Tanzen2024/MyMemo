<?php

use App\Filters\LocaleFilter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;

/**
 * Couvre le mécanisme de langue : FR par défaut, whitelist stricte contre
 * supportedLocales (jamais une valeur de cookie arbitraire), et le fait que
 * Config\App reste l'unique source de vérité — pas de système de traduction
 * parallèle. Le cookie est simulé via $_COOKIE (comme IncomingRequest le lit
 * réellement) plutôt qu'une méthode setCookie() inexistante côté requête.
 */
final class LocaleFilterTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[LocaleFilter::COOKIE_NAME]);
        parent::tearDown();
    }

    private function requestWithCookie(?string $locale)
    {
        if ($locale === null) {
            unset($_COOKIE[LocaleFilter::COOKIE_NAME]);
        } else {
            $_COOKIE[LocaleFilter::COOKIE_NAME] = $locale;
        }

        return service('request', null, false);
    }

    // TEST : Config\App::$defaultLocale est bien 'fr' (langue par défaut).
    public function testDefaultLocaleConfigIsFrench(): void
    {
        $this->assertSame('fr', config(AppConfig::class)->defaultLocale);
    }

    // TEST : les langues supportées sont exactement fr et en.
    public function testSupportedLocalesAreFrenchAndEnglish(): void
    {
        $this->assertSame(['fr', 'en'], config(AppConfig::class)->supportedLocales);
    }

    // TEST : aucun cookie -> locale par défaut (fr) appliquée à la requête.
    public function testNoCookieFallsBackToDefaultLocale(): void
    {
        $request = $this->requestWithCookie(null);
        (new LocaleFilter())->before($request);
        $this->assertSame('fr', $request->getLocale());
    }

    // TEST : cookie 'en' valide -> locale en appliquée.
    public function testValidEnglishCookieIsApplied(): void
    {
        $request = $this->requestWithCookie('en');
        (new LocaleFilter())->before($request);
        $this->assertSame('en', $request->getLocale());
    }

    // TEST : cookie 'fr' valide -> locale fr appliquée.
    public function testValidFrenchCookieIsApplied(): void
    {
        $request = $this->requestWithCookie('fr');
        (new LocaleFilter())->before($request);
        $this->assertSame('fr', $request->getLocale());
    }

    // TEST : valeur de cookie hors whitelist (supportedLocales) -> repli sur le défaut, jamais appliquée telle quelle.
    public function testUnsupportedCookieValueFallsBackToDefault(): void
    {
        $request = $this->requestWithCookie('xx');
        (new LocaleFilter())->before($request);
        $this->assertSame('fr', $request->getLocale());
    }

    // TEST : tentative d'injection (valeur non alphabétique) -> repli sur le défaut.
    public function testMaliciousCookieValueFallsBackToDefault(): void
    {
        $request = $this->requestWithCookie('../../etc/passwd');
        (new LocaleFilter())->before($request);
        $this->assertSame('fr', $request->getLocale());
    }
}
