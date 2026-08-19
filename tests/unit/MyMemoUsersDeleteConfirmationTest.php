<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le bouton Supprimer utilisait confirm() natif du navigateur ("localhost:3000
 * says"), remplacé par une modale Bootstrap déjà présente dans l'écosystème du
 * projet (même principe que #logoutConfirmModal de templates/footer.php).
 * Le contrôle serveur (CSRF, route, filtre auditadmin, écriture users.csv,
 * audit) est inchangé — seule la confirmation CÔTÉ NAVIGATEUR change.
 */
final class MyMemoUsersDeleteConfirmationTest extends CIUnitTestCase
{
    private function render(): string
    {
        return view('mymemo_users/index', [
            'users' => [[
                'username' => 'hugues.nwameh',
                'display_name' => 'Hugues Roland NWAMEH',
                'roles' => ['ADMIN'],
                'enabled' => true,
            ]],
            'search' => '',
            'knownRoles' => ['USER', 'ADMIN'],
        ]);
    }

    // TEST : plus aucune confirmation JS native (confirm()/alert()) dans les vues PROPRES au module
    // (le rendu complet de la page inclut templates/footer.php, qui contient légitimement un alert()
    // sans rapport, pour les erreurs de génération Postpaid/Prepaid — hors périmètre de ce contrôle).
    public function testNoNativeBrowserConfirmOrAlertRemainsInModuleViews(): void
    {
        foreach (['mymemo_users/index', 'mymemo_users/form'] as $view) {
            $source = file_get_contents(APPPATH . 'Views/' . $view . '.php');
            $this->assertStringNotContainsString('confirm(', $source, "confirm() trouvé dans {$view}.php");
            $this->assertStringNotContainsString('window.confirm', $source, "window.confirm trouvé dans {$view}.php");
            $this->assertStringNotContainsString('alert(', $source, "alert() trouvé dans {$view}.php");
            $this->assertStringNotContainsString('window.alert', $source, "window.alert trouvé dans {$view}.php");
        }
    }

    // TEST : la modale de confirmation MyMemo est présente, avec le nom d'utilisateur injecté dynamiquement (pas codé en dur).
    public function testMyMemoConfirmationModalIsPresent(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="deleteUserConfirmModal"', $html);
        $this->assertStringContainsString('Confirmation de suppression', $html);
        $this->assertStringContainsString('id="deleteUserConfirmName"', $html);
        $this->assertStringContainsString('id="deleteUserConfirmBtn"', $html);
    }

    // TEST : le formulaire de suppression reste un vrai formulaire POST (route/CSRF/CSV/audit inchangés), juste sans onsubmit=confirm().
    public function testDeleteFormKeepsRealPostSubmissionAndCsrf(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<form class="d-inline delete-user-form" method="post" action="[^"]*\/hugues\.nwameh\/delete"[^>]*data-username="hugues\.nwameh"/', $html);
        $this->assertStringNotContainsString('onsubmit=', $html);
        // csrf_field() est un input caché : présent quelque part dans les formulaires de la page.
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*csrf/', $html);
    }
}
