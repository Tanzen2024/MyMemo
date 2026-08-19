<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * templates/footer.php contient un script qui intercepte les soumissions de
 * formulaire (fetch + téléchargement du fichier de réponse), prévu UNIQUEMENT
 * pour les formulaires de génération Postpaid/Prepaid. Il ciblait auparavant
 * `document.querySelectorAll("form")` — TOUS les formulaires de TOUTE page —
 * ce qui cassait la navigation normale des formulaires GET/POST de Gestion
 * des utilisateurs et du Journal d'audit (fetch avec method GET + body lève
 * une exception ; pour un POST, la redirection serveur et son message flash
 * sont absorbés par fetch() au lieu d'atteindre le navigateur).
 */
final class FormAsyncScopingTest extends CIUnitTestCase
{
    // TEST : le script cible bien l'attribut data-async, pas "form" seul.
    public function testFooterScriptOnlyTargetsAsyncForms(): void
    {
        $footer = file_get_contents(APPPATH . 'Views/templates/footer.php');

        $this->assertStringContainsString('querySelectorAll(\'form[data-async="true"]\')', $footer);
        $this->assertStringNotContainsString('querySelectorAll("form")', $footer);
    }

    // TEST : les 4 formulaires de génération Postpaid/Prepaid portent bien data-async="true" (inchangé).
    public function testGenerationFormsStillCarryDataAsync(): void
    {
        foreach ([
            'memory/postpaid/particulier',
            'memory/postpaid/general',
            'memory/postpaid/etat',
            'prepaid',
        ] as $view) {
            $html = file_get_contents(APPPATH . 'Views/' . $view . '.php');
            $this->assertStringContainsString('data-async="true"', $html, "Formulaire de génération sans data-async : {$view}");
        }
    }

    // TEST : les formulaires de Gestion des utilisateurs (recherche + CRUD) ne portent PAS data-async — ils doivent naviguer normalement.
    public function testUserManagementFormsDoNotCarryDataAsync(): void
    {
        $index = file_get_contents(APPPATH . 'Views/mymemo_users/index.php');
        $form = file_get_contents(APPPATH . 'Views/mymemo_users/form.php');

        $this->assertStringNotContainsString('data-async', $index);
        $this->assertStringNotContainsString('data-async', $form);
    }

    // TEST : le formulaire de filtres du Journal d'audit ne porte pas data-async.
    public function testAuditFilterFormDoesNotCarryDataAsync(): void
    {
        $html = file_get_contents(APPPATH . 'Views/audit/index.php');
        $this->assertStringNotContainsString('data-async', $html);
    }
}
