<?php

namespace App\Services;

/**
 * Construit la synthèse affichée sur le Dashboard à partir des DEUX sources
 * déjà existantes : writable/security/users.csv (via MyMemoUserRepository)
 * et le Journal d'audit (via AuditReaderService/AuditFilterService) — aucune
 * nouvelle source de données. Une seule lecture de chaque, réutilisée pour
 * tous les indicateurs (voir docs/MYMEMO_USERS_CSV.md et
 * docs/AUDIT_DASHBOARD.md pour les mécanismes sous-jacents).
 */
final class DashboardService
{
    private const LOGIN_RANKING_LIMIT = 10;
    private const RECENT_LOGINS_LIMIT = 5;
    private const RECENT_ACTIVITY_LIMIT = 8;

    /** Événements jugés pertinents pour la synthèse « Activité récente » (§12 de la demande). */
    private const RECENT_ACTIVITY_ACTIONS = [
        'LOGIN_SUCCESS',
        'LOGIN_FAILED',
        'LOGIN_ERROR',
        'MEMORY_GENERATION_STARTED',
        'MEMORY_GENERATION_COMPLETED',
        'MEMORY_GENERATION_FAILED',
        'EXCEL_IMPORT_STARTED',
        'EXCEL_IMPORT_COMPLETED',
        'EXCEL_IMPORT_FAILED',
        'ACCESS_DENIED',
    ];

    public function __construct(
        private readonly MyMemoUserRepository $userRepository = new MyMemoUserRepository(),
        private readonly AuditReaderService $auditReader = new AuditReaderService(),
        private readonly AuditFilterService $auditFilter = new AuditFilterService(),
    ) {
    }

    /** @param array<string,string> $dateOverrides 'date_from'/'date_to' (YYYY-MM-DD) ; défaut : 30 derniers jours (AuditFilterService). */
    public function buildOverview(array $dateOverrides = []): array
    {
        try {
            $users = $this->userRepository->readAll();
        } catch (\Throwable $e) {
            // Le Dashboard est une synthèse, pas une porte d'accès : si
            // users.csv devient illisible, dégrader (compteurs à zéro)
            // plutôt que de faire planter la page d'accueil de tout le monde.
            log_message('critical', 'DashboardService : lecture de users.csv impossible : {msg}', ['msg' => $e->getMessage()]);
            $users = [];
        }

        $activeUsers = 0;
        $inactiveUsers = 0;
        $adminUsers = 0;
        $displayNames = [];
        foreach ($users as $key => $user) {
            $user['enabled'] ? $activeUsers++ : $inactiveUsers++;
            if (in_array('ADMIN', $user['roles'], true)) {
                $adminUsers++;
            }
            $displayNames[$key] = $user['display_name'] !== '' ? $user['display_name'] : $user['username'];
        }

        $filters = $this->auditFilter->sanitize($dateOverrides);
        $rows = $this->auditReader->find($filters); // une seule lecture, réutilisée ci-dessous pour tout

        [$loginCounts, $lastLogins] = $this->summarizeLogins($rows);

        arsort($loginCounts);
        $loginRanking = [];
        foreach (array_slice($loginCounts, 0, self::LOGIN_RANKING_LIMIT, true) as $key => $count) {
            $loginRanking[] = ['username' => $key, 'display_name' => $displayNames[$key] ?? $key, 'count' => $count];
        }

        arsort($lastLogins);
        $recentLogins = [];
        foreach (array_slice($lastLogins, 0, self::RECENT_LOGINS_LIMIT, true) as $key => $date) {
            $recentLogins[] = ['username' => $key, 'display_name' => $displayNames[$key] ?? $key, 'date' => $date];
        }

        $recentActivity = [];
        foreach ($rows as $row) {
            if (! in_array($row['action'] ?? '', self::RECENT_ACTIVITY_ACTIONS, true)) {
                continue;
            }
            $recentActivity[] = $row;
            if (count($recentActivity) >= self::RECENT_ACTIVITY_LIMIT) {
                break; // $rows déjà trié date desc : les premiers trouvés sont les plus récents
            }
        }

        return [
            'totalUsers'     => count($users),
            'activeUsers'    => $activeUsers,
            'inactiveUsers'  => $inactiveUsers,
            'adminUsers'     => $adminUsers,
            'loginRanking'   => $loginRanking,
            'recentLogins'   => $recentLogins,
            'recentActivity' => $recentActivity,
            'periodFrom'     => $filters['date_from'],
            'periodTo'       => $filters['date_to'],
        ];
    }

    /**
     * @return array{0: array<string,int>, 1: array<string,string>} [compteur par utilisateur, dernière date par utilisateur]
     */
    private function summarizeLogins(array $rows): array
    {
        $loginCounts = [];
        $lastLogins = [];

        foreach ($rows as $row) {
            if (($row['action'] ?? '') !== 'LOGIN_SUCCESS') {
                continue;
            }

            $key = $this->normalizeAuditUser((string) ($row['user'] ?? ''));
            if ($key === '' || $key === 'anonymous') {
                continue;
            }

            $loginCounts[$key] = ($loginCounts[$key] ?? 0) + 1;

            // $rows est trié date desc (AuditFilterService::sanitize) : la
            // première occurrence rencontrée pour une clé est déjà sa
            // connexion la plus récente — pas de second passage nécessaire.
            if (! isset($lastLogins[$key])) {
                $lastLogins[$key] = (string) $row['date'];
            }
        }

        return [$loginCounts, $lastLogins];
    }

    /** Même règle que MyMemoUserRepository::normalize(), en tenant compte des entrées d'audit héritées au format UPN (user@domaine). */
    private function normalizeAuditUser(string $user): string
    {
        return MyMemoUserRepository::normalize(strstr($user, '@', true) ?: $user);
    }
}
