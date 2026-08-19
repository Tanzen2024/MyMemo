<?php
namespace App\Filters;
use CodeIgniter\Filters\FilterInterface; use CodeIgniter\HTTP\RequestInterface; use CodeIgniter\HTTP\ResponseInterface;

/**
 * Réserve une route aux administrateurs MyMemo (membres du groupe AD
 * configuré via ldap.adminGroups, "MyMemoAdmins" par défaut).
 *
 * is_mymemo_admin est calculé une seule fois, au login, par
 * AuthentificationController::doLogin() (via App\Libraries\LdapGroupMatcher)
 * et mis en session — ce filtre se contente de le lire, plutôt que de
 * recomparer les groupes à chaque requête.
 */
final class AdminAuditFilter implements FilterInterface {

    public function before(RequestInterface $request, $arguments = null) {
        $s = session();

        if ($s->get('is_admin') === true || $s->get('is_mymemo_admin') === true) {
            return;
        }

        (new \App\Services\AuditLoggerService())->log('ACCESS_DENIED', 'FAILED', 0.0, $request, [
            'category' => 'AUTHORIZATION',
            'severity' => 'WARNING',
            'module'   => 'Administration',
            'message'  => 'Accès refusé au Journal d\'audit (groupe AD requis absent).',
        ]);

        return redirect()->to('/dashboard')->with('msg', 'Accès administrateur requis.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
