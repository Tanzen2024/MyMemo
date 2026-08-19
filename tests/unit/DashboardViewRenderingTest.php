<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Rendu réel de app/Views/dashboard.php avec des données représentatives
 * (mêmes clés que DashboardService::buildOverview()) : vérifie l'absence
 * d'erreur PHP et la présence des éléments attendus (cartes KPI, graphique,
 * noms non tronqués, libellés d'activité en français).
 */
final class DashboardViewRenderingTest extends CIUnitTestCase
{
    public function testDashboardRendersWithoutErrorAndShowsExpectedContent(): void
    {
        session()->set(['is_mymemo_admin' => true, 'username' => 'hugues.nwameh']);

        $html = view('dashboard', [
            'title' => 'Tableau de Bord',
            'period' => '30d',
            'totalUsers' => 3,
            'activeUsers' => 2,
            'inactiveUsers' => 1,
            'adminUsers' => 1,
            'loginRanking' => [
                ['username' => 'hugues.nwameh', 'display_name' => 'Hugues Roland NWAMEH', 'count' => 28],
                ['username' => 'sylvestre.tam', 'display_name' => 'Sylvestre TAM', 'count' => 17],
            ],
            'recentLogins' => [
                ['username' => 'hugues.nwameh', 'display_name' => 'Hugues Roland NWAMEH', 'date' => date('c')],
            ],
            'recentActivity' => [
                ['action' => 'LOGIN_SUCCESS', 'status' => 'SUCCESS', 'user' => 'hugues.nwameh', 'date' => date('c')],
                ['action' => 'MEMORY_GENERATION_FAILED', 'status' => 'FAILED', 'user' => 'jean.dupont', 'date' => date('c')],
            ],
            'periodFrom' => date('Y-m-d', strtotime('-30 days')),
            'periodTo' => date('Y-m-d'),
        ]);

        $this->assertStringContainsString('Utilisateurs actifs', $html);
        $this->assertStringContainsString('Utilisateurs inactifs', $html);
        $this->assertStringContainsString('Administrateurs', $html);
        $this->assertStringContainsString('loginRankingChart', $html);
        $this->assertStringContainsString('Hugues Roland NWAMEH', $html); // nom complet, non tronqué
        $this->assertStringContainsString('Sylvestre TAM', $html);
        $this->assertStringContainsString('Connexion réussie', $html);
        $this->assertStringContainsString('Échec de génération de mémoire', $html);
    }

    // TEST : aucune donnée (utilisateurs/connexions) → la page se rend quand même, sans erreur.
    public function testDashboardRendersWithEmptyData(): void
    {
        session()->set(['is_mymemo_admin' => false, 'username' => 'jean.dupont']);

        $html = view('dashboard', [
            'title' => 'Tableau de Bord',
            'period' => '30d',
            'totalUsers' => 0,
            'activeUsers' => 0,
            'inactiveUsers' => 0,
            'adminUsers' => 0,
            'loginRanking' => [],
            'recentLogins' => [],
            'recentActivity' => [],
            'periodFrom' => date('Y-m-d', strtotime('-30 days')),
            'periodTo' => date('Y-m-d'),
        ]);

        $this->assertStringContainsString('Aucune connexion enregistrée', $html);
        $this->assertStringContainsString('Aucune activité récente', $html);
    }
}
