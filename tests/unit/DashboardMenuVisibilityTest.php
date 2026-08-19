<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le menu « Administration » (Gestion des utilisateurs / Journal d'audit) ne
 * doit être visible dans le menu latéral que pour une session ADMIN — un
 * USER ne doit même pas voir le lien. Le contrôle serveur réel (accès direct
 * aux routes) reste couvert par MyMemoAuthorizationTest.php (AdminAuditFilter,
 * inchangé) ; ce test couvre seulement le rendu du menu.
 */
final class DashboardMenuVisibilityTest extends CIUnitTestCase
{
    // TEST : session ADMIN → le menu Administration est rendu.
    public function testAdminSeesAdministrationMenu(): void
    {
        session()->set(['is_mymemo_admin' => true, 'username' => 'hugues.nwameh']);

        $html = view('templates/header');

        $this->assertStringContainsString('administration/users', $html);
        $this->assertStringContainsString('administration/audit', $html);
    }

    // TEST : session USER (non admin) → le menu Administration est absent du HTML, pas seulement désactivé visuellement.
    public function testUserDoesNotSeeAdministrationMenu(): void
    {
        session()->set(['is_mymemo_admin' => false, 'username' => 'jean.dupont']);

        $html = view('templates/header');

        $this->assertStringNotContainsString('administration/users', $html);
        $this->assertStringNotContainsString('administration/audit', $html);
    }

    // TEST : le lien Dashboard est toujours présent, quel que soit le rôle, et placé avant Postpaid dans le HTML.
    public function testDashboardMenuIsPresentAndAbovePostpaid(): void
    {
        session()->set(['is_mymemo_admin' => false, 'username' => 'jean.dupont']);

        $html = view('templates/header');

        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Postpaid', $html);
        $this->assertLessThan(strpos($html, 'Postpaid'), strpos($html, 'Dashboard'));
    }
}
