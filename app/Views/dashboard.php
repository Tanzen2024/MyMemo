<?php
$actionLabels = [
    'LOGIN_SUCCESS' => 'Connexion réussie',
    'LOGIN_FAILED' => 'Échec de connexion',
    'LOGIN_ERROR' => 'Erreur de connexion',
    'MEMORY_GENERATION_STARTED' => 'Génération de mémoire démarrée',
    'MEMORY_GENERATION_COMPLETED' => 'Génération de mémoire terminée',
    'MEMORY_GENERATION_FAILED' => 'Échec de génération de mémoire',
    'EXCEL_IMPORT_STARTED' => 'Import Excel démarré',
    'EXCEL_IMPORT_COMPLETED' => 'Import Excel terminé',
    'EXCEL_IMPORT_FAILED' => "Échec d'import Excel",
    'ACCESS_DENIED' => 'Accès refusé',
];

// Fermetures locales (pas de fonctions globales) : cette vue peut être
// rendue plusieurs fois dans le même processus (tests), une déclaration de
// fonction globale provoquerait une erreur fatale de redéclaration.
$activityIconClass = static function (string $status): array {
    return match ($status) {
        'SUCCESS' => ['fa-check-circle', 'text-success'],
        'FAILED', 'ERROR' => ['fa-times-circle', 'text-danger'],
        'WARNING', 'REFUSED' => ['fa-exclamation-triangle', 'text-warning'],
        default => ['fa-info-circle', 'text-muted'],
    };
};

$formatAuditDate = static function (string $iso): string {
    try {
        return (new DateTime($iso))->format('d/m/Y H:i');
    } catch (\Exception $e) {
        return $iso;
    }
};

$periodLabels = ['today' => "Aujourd'hui", '7d' => '7 derniers jours', '30d' => '30 derniers jours', 'all' => 'Toutes les connexions'];
$periodLabel = $periodLabels[$period] ?? $periodLabels['30d'];
?>
<?= $this->include('templates/header') ?>
    <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="<?= site_url('dashboard') ?>" class="nav-link active">Accueil</a>
      </li>
    </ul>

    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <a class="nav-link logout-link" href="<?= site_url('authentification/logout') ?>">
          <i class="fas fa-sign-out-alt"></i> Déconnexion
        </a>
      </li>
    </ul>
  </nav>

  <div class="content-wrapper">
    <?= $this->include('templates/flash_messages') ?>
    <section class="content-header">
        <div class="container-fluid">
            <h1>Dashboard</h1>
            <p>Bienvenue sur MyMemo</p>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-lg-4 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?= (int) $activeUsers ?></h3>
                            <p>Utilisateurs actifs</p>
                        </div>
                        <div class="icon"><i class="fas fa-user-check"></i></div>
                    </div>
                </div>

                <div class="col-lg-4 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?= (int) $inactiveUsers ?></h3>
                            <p>Utilisateurs inactifs</p>
                        </div>
                        <div class="icon"><i class="fas fa-user-slash"></i></div>
                    </div>
                </div>

                <div class="col-lg-4 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?= (int) $adminUsers ?></h3>
                            <p>Administrateurs</p>
                        </div>
                        <div class="icon"><i class="fas fa-user-shield"></i></div>
                    </div>
                </div>
            </div>
            <p class="text-muted mb-4"><?= (int) $totalUsers ?> utilisateur<?= $totalUsers > 1 ? 's' : '' ?> au total dans users.csv</p>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                            <h3 class="card-title mb-0">Connexions utilisateurs — <?= esc($periodLabel) ?></h3>
                            <div class="btn-group btn-group-sm" role="group">
                                <?php foreach ($periodLabels as $key => $label): ?>
                                    <a class="btn btn-outline-secondary <?= $period === $key ? 'active' : '' ?>" href="<?= site_url('dashboard') . '?period=' . $key ?>"><?= esc($label) ?></a>
                                <?php endforeach ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($loginRanking)): ?>
                                <p class="text-muted text-center mb-0">Aucune connexion enregistrée sur cette période.</p>
                            <?php else: ?>
                                <div style="position:relative;height:<?= max(220, count($loginRanking) * 42) ?>px;">
                                    <canvas id="loginRankingChart"></canvas>
                                </div>
                            <?php endif ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h3 class="card-title mb-0">Dernières connexions</h3></div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($recentLogins as $entry): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <span><?= esc($entry['display_name']) ?></span>
                                        <small class="text-muted"><?= esc($formatAuditDate($entry['date'])) ?></small>
                                    </li>
                                <?php endforeach ?>
                                <?php if (empty($recentLogins)): ?>
                                    <li class="list-group-item text-muted text-center">Aucune connexion récente.</li>
                                <?php endif ?>
                            </ul>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3 class="card-title mb-0">Activité récente</h3></div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($recentActivity as $event): ?>
                                    <?php [$icon, $iconClass] = $activityIconClass($event['status'] ?? ''); ?>
                                    <li class="list-group-item">
                                        <i class="fas <?= $icon ?> <?= $iconClass ?>"></i>
                                        <?= esc($actionLabels[$event['action']] ?? $event['action']) ?>
                                        <br><small class="text-muted"><?= esc($event['user']) ?> — <?= esc($formatAuditDate($event['date'])) ?></small>
                                    </li>
                                <?php endforeach ?>
                                <?php if (empty($recentActivity)): ?>
                                    <li class="list-group-item text-muted text-center">Aucune activité récente.</li>
                                <?php endif ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>
<?= $this->include('templates/footer') ?>
<script src="<?= base_url('assets/adminlte/plugins/chart.js/Chart.min.js') ?>"></script>
<script>
(function () {
    var canvas = document.getElementById('loginRankingChart');
    if (!canvas) { return; }

    var labels = <?= json_encode(array_column($loginRanking, 'display_name'), JSON_UNESCAPED_UNICODE) ?>;
    var values = <?= json_encode(array_column($loginRanking, 'count')) ?>;

    new Chart(canvas.getContext('2d'), {
        type: 'horizontalBar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Connexions',
                data: values,
                backgroundColor: 'rgba(91, 141, 184, 0.75)',
                borderColor: 'rgba(91, 141, 184, 1)',
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            legend: { display: false },
            scales: {
                xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }],
                yAxes: [{ ticks: { autoSkip: false } }]
            }
        }
    });
})();
</script>
