<?php

namespace App\Controllers\Views;

use App\Controllers\BaseController;
use App\Services\AuditLoggerService;
use App\Services\MyMemoAuthorizationService;
use App\Services\MyMemoUserRepository;

class AuthentificationController extends BaseController
{
    protected AuditLoggerService $auditLogger;

    public function __construct()
    {
        $this->auditLogger = new AuditLoggerService();
    }

    /**
     * Messages associés à ?denied=CODE (voir AutoLogoutFilter). Un code
     * plutôt que le message en clair dans l'URL ; la session ayant été
     * détruite juste avant la redirection (session()->destroy() invalide
     * aussitôt le cookie), le flashdata classique (session()->with(...))
     * n'est pas fiable dans ce cas précis — contrairement au cas "non
     * connecté" ci-dessous, qui ne détruit rien et peut donc utiliser le
     * flashdata normalement.
     */
    private const DENIED_MESSAGES = [
        'session_expired' => 'Session expirée pour inactivité.',
        'unauthorized'     => "Votre compte n'est pas autorisé à utiliser MyMemo. Contactez votre administrateur.",
        'service_unavailable' => 'Service momentanément indisponible. Veuillez réessayer ou contacter un administrateur.',
    ];

    public function login()
    {
        if (session()->has('id_user')) {
            return redirect()->to('/dashboard');
        }

        $deniedReason = self::DENIED_MESSAGES[(string) $this->request->getGet('denied')] ?? null;

        return view('authentification/login', ['deniedReason' => $deniedReason]);
    }

    public function doLogin()
    {
        $startedAt = microtime(true);
        $session = session();
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $logLogin = function (string $status, string $reason, array $extraContext = []) use ($startedAt): void {
            $action = match ($status) {
                'SUCCESS' => 'LOGIN_SUCCESS',
                'FAILED'  => 'LOGIN_FAILED',
                default   => 'LOGIN_ERROR',
            };
            $severity = $status === 'SUCCESS' ? 'SUCCESS' : ($status === 'FAILED' ? 'WARNING' : 'ERROR');

            $this->auditLogger->log($action, $status, microtime(true) - $startedAt, $this->request, [
                'category' => 'AUTHENTICATION',
                'severity' => $severity,
                'message'  => $reason,
            ] + $extraContext);
        };

        // Active Directory accepts both sAMAccountName and UPN forms.
        if ($username === '' || $password === '' || ! preg_match('/^[A-Za-z0-9._-]+(?:@[A-Za-z0-9.-]+)?$/', $username)) {
            $logLogin('FAILED', 'Identifiants incorrects (format invalide ou champ manquant)');
            return redirect()->to('/authentification/login')->with('msg', 'Identifiants incorrects')->withInput();
        }

        if (!function_exists('ldap_connect')) {
            log_message('error', 'The LDAP PHP extension is unavailable.');
            $logLogin('ERROR', 'Extension PHP LDAP indisponible');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        $ldap = config('LDAP');
        if ($ldap->host === '' || $ldap->baseDn === '' || $ldap->domain === '') {
            log_message('error', 'LDAP authentication is not configured.');
            $logLogin('ERROR', 'Configuration LDAP absente');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        $uri = $ldap->uri();
        if ($uri === '') {
            log_message('error', 'LDAP URI is invalid.');
            $logLogin('ERROR', 'Configuration LDAP invalide');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        $connection = ldap_connect($uri);
        if ($connection === false) {
            $logLogin('ERROR', 'Connexion au serveur LDAP impossible');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $ldap->networkTimeout);
            ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $ldap->networkTimeout);

            if ($ldap->startTls && ! @ldap_start_tls($connection)) {
                log_message('error', 'LDAP StartTLS failed (code {code}): {error}', ['code' => ldap_errno($connection), 'error' => ldap_error($connection)]);
                $logLogin('ERROR', 'Échec de négociation TLS LDAP');
                return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
            }

            $upn = str_contains($username, '@') ? $username : $username . '@' . $ldap->domain;
            $samAccountName = strstr($username, '@', true) ?: $username;

            if (!@ldap_bind($connection, $upn, $password)) {
                log_message('warning', 'LDAP bind failed (code {code}): {error}', ['code' => ldap_errno($connection), 'error' => ldap_error($connection)]);
                $logLogin('FAILED', 'Échec du bind LDAP (identifiants incorrects)');
                return redirect()->to('/authentification/login')->with('msg', 'Identifiants incorrects')->withInput();
            }

            $filter = '(sAMAccountName=' . ldap_escape($samAccountName, '', LDAP_ESCAPE_FILTER) . ')';
            $search = @ldap_search($connection, $ldap->baseDn, $filter, ['objectGUID', 'cn', 'memberOf']);
            $entries = $search === false ? false : ldap_get_entries($connection, $search);
            if ($entries === false || $entries['count'] < 1) {
                if ($search === false) {
                    log_message('warning', 'LDAP search failed (code {code}): {error}', ['code' => ldap_errno($connection), 'error' => ldap_error($connection)]);
                }
                $logLogin('FAILED', 'Compte introuvable dans l\'annuaire');
                return redirect()->to('/authentification/login')->with('msg', 'Compte introuvable.');
            }

            $groups = [];
            for ($g = 0; $g < ($entries[0]['memberof']['count'] ?? 0); $g++) {
                $groups[] = $entries[0]['memberof'][$g];
            }

            // AD authentifie ; l'AUTORISATION (rôles fonctionnels MyMemo)
            // vient exclusivement de writable/security/users.csv, jamais des
            // groupes AD (voir docs/MYMEMO_USERS_CSV.md). $samAccountName
            // est l'identifiant AD canonique déjà utilisé pour la recherche
            // LDAP ci-dessus (indépendant de la forme saisie, UPN ou non) :
            // c'est la même clé qui indexe le CSV.
            $canonicalUsername = MyMemoUserRepository::normalize($samAccountName);

            try {
                $resolved = (new MyMemoAuthorizationService())->resolveSession($canonicalUsername);
            } catch (\Throwable $e) {
                log_message('critical', 'doLogin : lecture de users.csv impossible : {msg}', ['msg' => $e->getMessage()]);
                $logLogin('ERROR', 'users.csv illisible (permissions/disque)');
                return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
            }

            if (! $resolved['authorized']) {
                $this->auditLogger->log('ACCESS_DENIED', 'FAILED', microtime(true) - $startedAt, $this->request, [
                    'category' => 'AUTHORIZATION',
                    'severity' => 'WARNING',
                    'module'   => 'MyMemo',
                    'user'     => $canonicalUsername,
                    'message'  => 'Accès refusé : utilisateur AD authentifié mais absent de users.csv, désactivé, ou sans rôle attribué.',
                ]);

                return redirect()->to('/authentification/login?denied=unauthorized');
            }

            $session->regenerate(true);
            $session->set([
                'id_user' => bin2hex($entries[0]['objectguid'][0] ?? $username),
                'username' => $canonicalUsername,
                'display_name' => $resolved['display_name'] ?? ($entries[0]['cn'][0] ?? $username),
                'groups' => $groups,
                'roles' => $resolved['roles'],
                'is_mymemo_user' => $resolved['is_mymemo_user'],
                'is_mymemo_admin' => $resolved['is_mymemo_admin'],
                'last_activity' => time(),
            ]);

            $logLogin('SUCCESS', 'Authentification LDAP réussie', [
                'display_name'    => $resolved['display_name'] ?? ($entries[0]['cn'][0] ?? $username),
                'roles'           => $resolved['roles'],
                'is_mymemo_user'  => $resolved['is_mymemo_user'],
                'is_mymemo_admin' => $resolved['is_mymemo_admin'],
            ]);

            return redirect()->to('/dashboard');
        } finally {
            ldap_unbind($connection);
        }
    }

    public function logout()
    {
        $startedAt = microtime(true);
        $username = (string) (session()->get('username') ?? 'inconnu');

        session()->destroy();

        $this->auditLogger->log('LOGOUT', 'SUCCESS', microtime(true) - $startedAt, $this->request, [
            'category' => 'AUTHENTICATION',
            'severity' => 'INFO',
            'user'     => $username,
        ]);

        return redirect()->to('/authentification/login');
    }
}
