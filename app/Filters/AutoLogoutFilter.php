<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class AutoLogoutFilter implements FilterInterface
{
    /**
     * Durée max d'inactivité (secondes)
     */
    private int $timeout;

    /**
     * Routes accessibles sans authentification
     */
    private array $excludedRoutes = [
        'authentification/login',
        'authentification/logout',
    ];

    public function __construct()
    {
        // Lecture depuis .env (fallback 15 min)
        $this->timeout = (int) env('SESSION_TIMEOUT', 900);
    }

    /**
     * Exécuté AVANT le contrôleur
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        // Chemin courant (ex: auth/login)
        $currentPath = trim($request->getUri()->getPath(), '/');

        // Ignorer les routes publiques
        if (in_array($currentPath, $this->excludedRoutes)) {
            return;
        }

        // Vérifier la connexion
        if (! $session->has('id_user')) {
            return redirect()
                ->to('/authentification/login')
                ->with('gestReturnInfo', 'Veuillez vous reconnecter.');
        }

        // Vérifier l'inactivité
        $lastActivity = $session->get('last_activity');

        if ($lastActivity && (time() - $lastActivity) > $this->timeout) {
            $username = (string) ($session->get('username') ?? 'inconnu');
            $session->destroy();

            (new \App\Services\AuditLoggerService())->log('SESSION_EXPIRED', 'SUCCESS', 0.0, $request, [
                'category' => 'AUTHENTICATION',
                'severity' => 'INFO',
                'user'     => $username,
                'message'  => 'Session expirée pour inactivité.',
            ]);

            // Pas de flashdata ici : session()->destroy() ci-dessus invalide
            // aussitôt le cookie de session (Set-Cookie ...=deleted), donc un
            // ->with() sur CETTE redirection ne serait jamais lu par le
            // navigateur — le message passe par ?denied=, lu par
            // AuthentificationController::login().
            return redirect()->to('/authentification/login?denied=session_expired');
        }

        // Autorisation MyMemo : authentifié ne suffit pas. La source de
        // vérité des rôles est writable/security/users.csv (jamais les
        // groupes AD) — relue à CHAQUE requête (pas seulement au login) pour
        // qu'une modification de rôle prenne effet immédiatement, sans
        // attendre une reconnexion (voir MyMemoAuthorizationService et
        // docs/MYMEMO_USERS_CSV.md). C'est ce même rafraîchissement qui
        // garde AdminAuditFilter::before() inchangé : il lit
        // session('is_mymemo_admin'), toujours frais grâce à ce filtre
        // global exécuté avant les filtres de route.
        $username = (string) ($session->get('username') ?? '');

        try {
            $resolved = (new \App\Services\MyMemoAuthorizationService())->resolveSession($username);
        } catch (\Throwable $e) {
            // users.csv illisible (permissions, disque...) : ne jamais planter
            // toutes les pages avec une 500 ; refuser proprement comme le
            // ferait un utilisateur absent du CSV (deny-by-default), le
            // détail technique part dans les logs applicatifs, pas dans
            // l'URL ni le Journal d'audit métier.
            log_message('critical', 'AutoLogoutFilter : lecture de users.csv impossible : {msg}', ['msg' => $e->getMessage()]);
            $session->destroy();

            return redirect()->to('/authentification/login?denied=service_unavailable');
        }

        $session->set([
            'roles'           => $resolved['roles'],
            'is_mymemo_user'  => $resolved['is_mymemo_user'],
            'is_mymemo_admin' => $resolved['is_mymemo_admin'],
        ]);

        if (! $resolved['is_mymemo_user']) {
            // Détruire la session : sinon AuthentificationController::login()
            // (id_user encore présent) redirigerait aussitôt vers /dashboard,
            // que ce filtre bloquerait à nouveau — boucle de redirection.
            $session->destroy();

            (new \App\Services\AuditLoggerService())->log('ACCESS_DENIED', 'FAILED', 0.0, $request, [
                'category' => 'AUTHORIZATION',
                'severity' => 'WARNING',
                'module'   => 'MyMemo',
                'user'     => $username !== '' ? $username : 'inconnu',
                'message'  => 'Accès refusé : utilisateur AD authentifié mais non autorisé à utiliser MyMemo (absent de users.csv, désactivé, ou sans rôle).',
            ]);

            // Même raison qu'au cas SESSION_EXPIRED ci-dessus : destroy()
            // invalide le cookie, ->with() serait perdu ; message via ?denied=.
            return redirect()->to('/authentification/login?denied=unauthorized');
        }

        // Mise à jour de l'activité
        $session->set('last_activity', time());
    }

    /**
     * Exécuté APRÈS le contrôleur
     */
    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        // Optionnel :
        // - logs
        // - headers sécurité
    }
}
