<?php

namespace App\Controllers\Views;

use App\Controllers\BaseController;
use App\Services\AuditLoggerService;

class AuthentificationController extends BaseController
{
    protected AuditLoggerService $auditLogger;

    public function __construct()
    {
        $this->auditLogger = new AuditLoggerService();
    }

    public function login()
    {
        if (session()->has('id_user')) {
            return redirect()->to('/dashboard');
        }

        return view('authentification/login');
    }

    public function doLogin()
    {
        $startedAt = microtime(true);
        $session = session();
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $logLogin = function (string $status, string $reason) use ($startedAt): void {
            $this->auditLogger->log('login', $status, microtime(true) - $startedAt, $this->request, [
                'reason'   => $reason,
            ]);
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

            // Nécessaire pour AdminAuditFilter, qui autorise /administration/audit
            // aux membres du groupe AD "CN=Admins,OU=Groups,DC=camlight,DC=cm".
            $groups = [];
            for ($g = 0; $g < ($entries[0]['memberof']['count'] ?? 0); $g++) {
                $groups[] = $entries[0]['memberof'][$g];
            }

            $session->regenerate(true);
            $session->set([
                'id_user' => bin2hex($entries[0]['objectguid'][0] ?? $username),
                'username' => $username,
                'display_name' => $entries[0]['cn'][0] ?? $username,
                'groups' => $groups,
                'last_activity' => time(),
            ]);

            $logLogin('SUCCESS', 'Authentification LDAP réussie');

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

        $this->auditLogger->log('logout', 'SUCCESS', microtime(true) - $startedAt, $this->request, [
            'username' => $username,
        ]);

        return redirect()->to('/authentification/login');
    }
}
