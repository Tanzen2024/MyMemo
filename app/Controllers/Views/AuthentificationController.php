<?php

namespace App\Controllers\Views;

use App\Controllers\BaseController;

class AuthentificationController extends BaseController
{
    public function login()
    {
        if (session()->has('id_user')) {
            return redirect()->to('/dashboard');
        }

        return view('authentification/login');
    }

    public function doLogin()
    {
        $session = session();
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        if ($username === '' || $password === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            return redirect()->to('/authentification/login')->with('msg', 'Identifiants incorrects')->withInput();
        }

        if (!function_exists('ldap_connect')) {
            log_message('error', 'The LDAP PHP extension is unavailable.');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        $ldap = config('LDAP');
        if ($ldap->host === '' || $ldap->baseDn === '' || $ldap->domain === '') {
            log_message('error', 'LDAP authentication is not configured.');
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        $connection = ldap_connect($ldap->host, $ldap->port);
        if ($connection === false) {
            return redirect()->to('/authentification/login')->with('msg', 'Service de connexion indisponible.');
        }

        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

            if (!@ldap_bind($connection, $username . '@' . $ldap->domain, $password)) {
                return redirect()->to('/authentification/login')->with('msg', 'Identifiants incorrects')->withInput();
            }

            $filter = '(sAMAccountName=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
            $search = ldap_search($connection, $ldap->baseDn, $filter, ['objectGUID', 'cn']);
            $entries = $search === false ? false : ldap_get_entries($connection, $search);
            if ($entries === false || $entries['count'] < 1) {
                return redirect()->to('/authentification/login')->with('msg', 'Compte introuvable.');
            }

            $session->regenerate(true);
            $session->set([
                'id_user' => bin2hex($entries[0]['objectguid'][0] ?? $username),
                'username' => $username,
                'display_name' => $entries[0]['cn'][0] ?? $username,
                'last_activity' => time(),
            ]);

            return redirect()->to('/dashboard');
        } finally {
            ldap_unbind($connection);
        }
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/authentification/login');
    }
}
