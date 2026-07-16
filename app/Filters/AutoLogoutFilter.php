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
            $session->destroy();

            return redirect()
                ->to('/authentification/login')
                ->with('gestReturnInfo', 'Session expirée pour inactivité.');
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
