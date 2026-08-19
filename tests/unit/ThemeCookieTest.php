<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le thème n'a pas de filtre/contrôleur dédié (bascule 100% client, voir
 * app/Views/templates/topbar_actions.php) : seule la LECTURE du cookie
 * mymemo_theme côté serveur (pour poser data-theme avant le premier rendu,
 * sans flash) est testable côté PHP. Couvre header.php (layout partagé) et
 * login.php (page autonome, sans layout).
 */
final class ThemeCookieTest extends CIUnitTestCase
{
    // service('request') est partagé (singleton) : sans reset, un $_COOKIE
    // modifié après la première construction de la requête ne serait jamais
    // relu (même mécanisme de pollution inter-tests que LanguageControllerTest).
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetServices();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['mymemo_theme']);
        parent::tearDown();
    }

    private function renderLogin(): string
    {
        return view('authentification/login', ['deniedReason' => null]);
    }

    // TEST : aucun cookie -> thème clair par défaut sur la page de login.
    public function testLoginDefaultsToLightTheme(): void
    {
        unset($_COOKIE['mymemo_theme']);
        $this->assertStringContainsString('data-theme="light"', $this->renderLogin());
    }

    // TEST : cookie 'dark' valide -> thème sombre appliqué dès le rendu serveur (pas de flash clair->sombre).
    public function testLoginAppliesValidDarkCookie(): void
    {
        $_COOKIE['mymemo_theme'] = 'dark';
        $this->assertStringContainsString('data-theme="dark"', $this->renderLogin());
    }

    // TEST : valeur de cookie invalide -> repli sur clair (deny-by-default, même logique que la langue).
    public function testLoginIgnoresInvalidThemeCookie(): void
    {
        $_COOKIE['mymemo_theme'] = 'not-a-theme';
        $this->assertStringContainsString('data-theme="light"', $this->renderLogin());
    }

    // TEST : le layout partagé (templates/header.php, utilisé par toutes les pages authentifiées) applique la même règle.
    public function testSharedHeaderAppliesValidDarkCookie(): void
    {
        $_COOKIE['mymemo_theme'] = 'dark';
        $html = view('mymemo_users/index', ['users' => [], 'search' => '', 'knownRoles' => ['USER', 'ADMIN']]);
        $this->assertStringContainsString('data-theme="dark"', $html);
    }
}
