<?php

use App\Controllers\LanguageController;
use App\Filters\LocaleFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre le contrôleur qui pose le cookie de langue. Le point historique
 * important : redirect()->back() construit sa PROPRE réponse — un cookie posé
 * séparément sur $this->response n'atteindrait jamais le navigateur (bug
 * trouvé et corrigé pendant cette tâche). Le cookie doit être chaîné sur
 * l'objet réellement retourné.
 */
final class LanguageControllerTest extends CIUnitTestCase
{
    // redirect() renvoie le service PARTAGÉ 'redirectresponse' (singleton) :
    // sans reset, les cookies posés par un test précédent persisteraient sur
    // le même objet et fausseraient les assertions suivantes.
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetServices();
    }

    private function switchTo(string $locale)
    {
        return (new LanguageController())->switch($locale);
    }

    // TEST : une locale supportée pose bien le cookie sur la réponse RÉELLEMENT retournée.
    public function testSupportedLocaleSetsCookieOnReturnedResponse(): void
    {
        $response = $this->switchTo('en');
        $cookie = $response->getCookie(LocaleFilter::COOKIE_NAME);

        $this->assertNotNull($cookie, 'Le cookie doit être présent sur la réponse retournée par le contrôleur.');
        $this->assertSame('en', $cookie->getValue());
    }

    // TEST : la locale française (l'autre valeur supportée) fonctionne aussi.
    public function testFrenchLocaleSetsCookie(): void
    {
        $response = $this->switchTo('fr');
        $cookie = $response->getCookie(LocaleFilter::COOKIE_NAME);

        $this->assertNotNull($cookie);
        $this->assertSame('fr', $cookie->getValue());
    }

    // TEST : une locale non supportée n'est jamais posée en cookie (deny-by-default, même logique que LocaleFilter).
    public function testUnsupportedLocaleDoesNotSetCookie(): void
    {
        $response = $this->switchTo('xx');

        $this->assertFalse($response->hasCookie(LocaleFilter::COOKIE_NAME));
    }

    // TEST : le contrôleur redirige toujours (jamais d'affichage direct), quelle que soit la validité de la locale.
    public function testAlwaysRedirects(): void
    {
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $this->switchTo('en'));
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $this->switchTo('xx'));
    }

    // TEST : le cookie posé a une durée de vie d'environ 1 an (persiste après déconnexion, cf. plan) — ni quelques heures, ni des décennies.
    public function testCookieHasOneYearExpiry(): void
    {
        $cookie = $this->switchTo('en')->getCookie(LocaleFilter::COOKIE_NAME);
        $ttl = $cookie->getExpiresTimestamp() - time();
        $this->assertGreaterThan(300 * 86400, $ttl);
        $this->assertLessThan(400 * 86400, $ttl);
    }
}
