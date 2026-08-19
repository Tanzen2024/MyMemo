<?php

use App\Services\AuditFilterService;
use App\Services\AuditReaderService;
use App\Services\DashboardService;
use App\Services\MyMemoUserRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre la synthèse du Dashboard : compteurs utilisateurs (actifs/inactifs/
 * administrateurs, depuis users.csv), classement des connexions (Journal
 * d'audit, tri décroissant, limite, fenêtre de 30 jours), absence
 * d'événements.
 */
final class DashboardServiceTest extends CIUnitTestCase
{
    private string $usersDir;
    private string $auditDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usersDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-dash-users-' . uniqid();
        $this->auditDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-dash-audit-' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($this->usersDir, 0750, true);
        mkdir($this->auditDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->usersDir . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*') ?: []) as $f) {
            unlink($f);
        }
        @rmdir($this->usersDir . DIRECTORY_SEPARATOR . 'backups');
        foreach (glob($this->usersDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->usersDir);

        foreach (glob($this->auditDir . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->auditDir);
        parent::tearDown();
    }

    private function writeUsersCsv(array $rows): void
    {
        $handle = fopen($this->usersDir . DIRECTORY_SEPARATOR . 'users.csv', 'w');
        fputcsv($handle, ['username', 'roles', 'enabled', 'display_name']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private function writeAuditEvent(int $daysAgo, string $user, string $action, string $status = 'SUCCESS'): void
    {
        $timestamp = strtotime("-{$daysAgo} days");
        $day = date('Y-m-d', $timestamp);
        $file = $this->auditDir . $day . '.jsonl';
        $entry = [
            'date' => date('c', $timestamp),
            'user' => $user,
            'action' => $action,
            'status' => $status,
            'category' => 'AUTHENTICATION',
        ];
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    private function service(): DashboardService
    {
        return new DashboardService(
            new MyMemoUserRepository($this->usersDir),
            new AuditReaderService($this->auditDir),
            new AuditFilterService()
        );
    }

    // TEST : nombre d'utilisateurs actifs/inactifs/administrateurs correct.
    public function testActiveInactiveAndAdminCounts(): void
    {
        $this->writeUsersCsv([
            ['hugues.nwameh', 'ADMIN', '1', 'Hugues NWAMEH'],
            ['sylvestre.tam', 'ADMIN', '1', 'Sylvestre TAM'],
            ['jean.dupont', 'USER', '1', 'Jean DUPONT'],
            ['paul.martin', 'USER', '0', 'Paul MARTIN'],
        ]);

        $overview = $this->service()->buildOverview();

        $this->assertSame(4, $overview['totalUsers']);
        $this->assertSame(3, $overview['activeUsers']);
        $this->assertSame(1, $overview['inactiveUsers']);
        $this->assertSame(2, $overview['adminUsers']);
    }

    // TEST : absence totale d'événements d'audit → classement/activité vides, pas d'erreur.
    public function testNoAuditEventsYieldsEmptyRankingAndActivity(): void
    {
        $this->writeUsersCsv([['jean.dupont', 'USER', '1', 'Jean DUPONT']]);

        $overview = $this->service()->buildOverview();

        $this->assertSame([], $overview['loginRanking']);
        $this->assertSame([], $overview['recentLogins']);
        $this->assertSame([], $overview['recentActivity']);
    }

    // TEST : classement des connexions trié décroissant.
    public function testLoginRankingSortedDescending(): void
    {
        $this->writeUsersCsv([
            ['hugues.nwameh', 'ADMIN', '1', 'Hugues NWAMEH'],
            ['sylvestre.tam', 'ADMIN', '1', 'Sylvestre TAM'],
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->writeAuditEvent(1, 'hugues.nwameh', 'LOGIN_SUCCESS');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->writeAuditEvent(1, 'sylvestre.tam', 'LOGIN_SUCCESS');
        }

        $ranking = $this->service()->buildOverview()['loginRanking'];

        $this->assertSame('hugues.nwameh', $ranking[0]['username']);
        $this->assertSame(5, $ranking[0]['count']);
        $this->assertSame('sylvestre.tam', $ranking[1]['username']);
        $this->assertSame(2, $ranking[1]['count']);
    }

    // TEST : classement limité à 10 utilisateurs même si davantage se sont connectés.
    public function testLoginRankingIsCappedAtTen(): void
    {
        $this->writeUsersCsv([['jean.dupont', 'USER', '1', 'Jean DUPONT']]);

        for ($i = 1; $i <= 12; $i++) {
            $this->writeAuditEvent(1, "user{$i}", 'LOGIN_SUCCESS');
        }

        $ranking = $this->service()->buildOverview()['loginRanking'];

        $this->assertCount(10, $ranking);
    }

    // TEST : identifiants hérités au format UPN (user@domaine) regroupés avec la forme courte.
    public function testLegacyUpnFormatIsGroupedWithShortUsername(): void
    {
        $this->writeUsersCsv([['hugues.nwameh', 'ADMIN', '1', 'Hugues NWAMEH']]);

        $this->writeAuditEvent(1, 'hugues.nwameh@camlight.cm', 'LOGIN_SUCCESS');
        $this->writeAuditEvent(1, 'hugues.nwameh', 'LOGIN_SUCCESS');

        $ranking = $this->service()->buildOverview()['loginRanking'];

        $this->assertCount(1, $ranking);
        $this->assertSame(2, $ranking[0]['count']);
    }

    // TEST : un événement hors de la fenêtre de 30 jours (défaut) est exclu.
    public function testEventOlderThanThirtyDaysIsExcludedByDefault(): void
    {
        $this->writeUsersCsv([['jean.dupont', 'USER', '1', 'Jean DUPONT']]);

        $this->writeAuditEvent(45, 'jean.dupont', 'LOGIN_SUCCESS'); // hors fenêtre
        $this->writeAuditEvent(5, 'jean.dupont', 'LOGIN_SUCCESS');  // dans la fenêtre

        $ranking = $this->service()->buildOverview()['loginRanking'];

        $this->assertSame(1, $ranking[0]['count']);
    }

    // TEST : activité récente limitée aux actions pertinentes, triée du plus récent au plus ancien.
    public function testRecentActivityFiltersAndSortsByDate(): void
    {
        $this->writeUsersCsv([['jean.dupont', 'USER', '1', 'Jean DUPONT']]);

        $this->writeAuditEvent(3, 'jean.dupont', 'MEMORY_GENERATION_STARTED');
        $this->writeAuditEvent(2, 'jean.dupont', 'SOME_UNRELATED_EVENT');
        $this->writeAuditEvent(1, 'jean.dupont', 'LOGIN_FAILED', 'FAILED');

        $activity = $this->service()->buildOverview()['recentActivity'];

        $actions = array_column($activity, 'action');
        $this->assertNotContains('SOME_UNRELATED_EVENT', $actions);
        $this->assertSame('LOGIN_FAILED', $actions[0]); // le plus récent en premier
    }

    // TEST : users.csv inaccessible → le Dashboard se dégrade (compteurs à zéro), ne plante pas la page d'accueil.
    public function testUnreadableUsersCsvDegradesGracefullyInsteadOfThrowing(): void
    {
        $unreadableDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-dash-unreadable-' . uniqid();
        mkdir($unreadableDir, 0750, true);
        mkdir($unreadableDir . DIRECTORY_SEPARATOR . 'users.csv');

        $service = new DashboardService(
            new MyMemoUserRepository($unreadableDir),
            new AuditReaderService($this->auditDir),
            new AuditFilterService()
        );

        $overview = $service->buildOverview();

        $this->assertSame(0, $overview['totalUsers']);
        $this->assertSame(0, $overview['activeUsers']);

        foreach (glob($unreadableDir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($unreadableDir);
    }
}
