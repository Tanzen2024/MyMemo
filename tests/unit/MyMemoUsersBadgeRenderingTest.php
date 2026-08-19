<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le badge du rôle USER était rendu avec la classe Bootstrap "badge-light"
 * (fond quasi blanc sur une carte blanche), le rendant illisible — contraire
 * à ADMIN ("badge-primary", bien visible). Ce test vérifie le rendu HTML réel
 * de la page Gestion des utilisateurs pour ADMIN seul, USER seul, et les deux
 * rôles combinés.
 */
final class MyMemoUsersBadgeRenderingTest extends CIUnitTestCase
{
    private function renderIndex(array $roles): string
    {
        return view('mymemo_users/index', [
            'users' => [[
                'username' => 'jean.dupont',
                'display_name' => 'Jean DUPONT',
                'roles' => $roles,
                'enabled' => true,
            ]],
            'search' => '',
            'knownRoles' => ['USER', 'ADMIN'],
        ]);
    }

    // TEST : badge ADMIN toujours rendu avec badge-primary.
    public function testAdminBadgeUsesPrimaryClass(): void
    {
        $html = $this->renderIndex(['ADMIN']);
        $this->assertStringContainsString('badge badge-primary">ADMIN<', $html);
    }

    // TEST : badge USER ne doit plus utiliser badge-light (illisible) mais badge-info (contraste correct).
    public function testUserBadgeUsesInfoClassNotLight(): void
    {
        $html = $this->renderIndex(['USER']);
        $this->assertStringContainsString('badge badge-info">USER<', $html);
        $this->assertStringNotContainsString('badge-light', $html);
    }

    // TEST : rôles multiples (ADMIN + USER) → les deux badges sont rendus, chacun avec la bonne classe.
    public function testMultipleRolesRenderBothBadgesDistinctly(): void
    {
        $html = $this->renderIndex(['ADMIN', 'USER']);
        $this->assertStringContainsString('badge badge-primary">ADMIN<', $html);
        $this->assertStringContainsString('badge badge-info">USER<', $html);
    }
}
