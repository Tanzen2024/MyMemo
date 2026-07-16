<?php

namespace App\Controllers\Views;

use CodeIgniter\Controller;

class AuthentificationController extends Controller
{
    /**
     * Affichage du formulaire de connexion
     */
    public function login()
    {
        helper(['form', 'url']);
        $session = session();

        // Si déjà connecté → dashboard
        if ($session->has('isLoggedIn') && $session->get('isLoggedIn') === true) {
            // Redirection selon groupe
            $groups = $session->get('groups') ?? [];
            if (in_array('CN=Admins,OU=Groups,DC=camlight,DC=cm', $groups)) {
                return redirect()->to('/admin/dashboard');
            } else {
                return redirect()->to('/user/dashboard');
            }
        }

        return view('authentification/login');
    }

    /**
     * Traitement du formulaire de connexion via LDAP
     */
    public function doLogin()
    {
        helper(['form', 'url']);
        $session = session();

        $username = trim($this->request->getPost('username'));
        $password = trim($this->request->getPost('password'));

        // Validation simple
        if ($username === '' || $password === '') {
            return redirect()
                ->to('/authentification/login')
                ->with('msg', 'Veuillez remplir tous les champs')
                ->withInput();
        }

        // 🔹 Connexion LDAP
        $ldapConfig = config('LDAP');
        $ldapconn = ldap_connect($ldapConfig->host, $ldapConfig->port);
        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);

        $ldaprdn = $username . $ldapConfig->domain;

        if (@ldap_bind($ldapconn, $ldaprdn, $password)) {
            // Recherche info utilisateur
            $filter = "(sAMAccountName=$username)";
            $result = ldap_search($ldapconn, $ldapConfig->base_dn, $filter);
            $entries = ldap_get_entries($ldapconn, $result);

            if ($entries['count'] > 0) {
                // 🔐 Sécurité : régénération de session
                $session->regenerate(true);

                // Création de session
                $sessionData = [
                    'id_user'       => $entries[0]['objectGUID'][0] ?? $username, // tu peux adapter l'ID
                    'username'      => $username,
                    'display_name'  => $entries[0]['cn'][0],
                    'groups'        => isset($entries[0]['memberOf']) ? $entries[0]['memberOf'] : [],
                    'isLoggedIn'    => true,
                    'last_activity' => time(),
                ];
                $session->set($sessionData);

                // Redirection selon groupe
                if (in_array('CN=Admins,OU=Groups,DC=camlight,DC=cm', $sessionData['groups'])) {
                    return redirect()->to('/admin/dashboard');
                } else {
                    return redirect()->to('/user/dashboard');
                }
            }
        }

        // Échec de connexion LDAP
        $session->setFlashdata('msg', 'Nom d’utilisateur ou mot de passe incorrect');
        return redirect()->to('/authentification/login')->withInput();
    }

    /**
     * Déconnexion
     */
    public function logout()
    {
        session()->destroy();
        return redirect()->to('/authentification/login');
    }
}
