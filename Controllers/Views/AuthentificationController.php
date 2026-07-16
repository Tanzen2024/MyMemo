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
        $session = session();

        // Si déjà connecté → dashboard
        if ($session->has('id_user')) {
            return redirect()->to('/dashboard');
        }

        return view('authentification/login');
    }

    /**
     * Traitement du formulaire de connexion
     */
    public function doLogin()
    {
        $session = session();

        // 🔹 Données utilisateurs (TEST)
        // 👉 À remplacer plus tard par une table users
        $users_test = [];

        // Récupération des champs
        $username = trim($this->request->getPost('username'));
        $password = trim($this->request->getPost('password'));

        // Validation
        if ($username === '' || $password === '') {
            return redirect()
                ->to('/authentification/login')
                ->with('msg', 'Veuillez remplir tous les champs')
                ->withInput();
        }

        // Vérification des identifiants
        if (
            isset($users_test[$username]) &&
            $users_test[$username]['password'] === $password
        ) {
            // 🔐 Sécurité : régénération de session
            $session->regenerate(true);

            // 📌 Données attendues par le filtre
            $session->set([
                'id_user'       => $users_test[$username]['id'],
                'username'      => $username,
                'last_activity' => time(),
            ]);

            return redirect()->to('/dashboard');
        }

        // Échec
        return redirect()
            ->to('/authentification/login')
            ->with('msg', 'Identifiants incorrects')
            ->withInput();
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
