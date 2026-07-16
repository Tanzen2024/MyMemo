<?php

namespace App\Controllers\Views;

use App\Controllers\BaseController;

class DisplayViewsController extends BaseController
{
    public function particulier()
    {
        // Charge la vue pour le particulier
        return view('memory/postpaid/particulier', ['title' => 'Particulier']);
    }

    public function general()
    {
        // Charge la vue pour le général
        return view('memory/postpaid/general', ['title' => 'Général']);
    }

    public function etat()
    {
        // Charge la vue pour l'état
        return view('memory/postpaid/etat', ['title' => 'État']);
    } 

    public function prepaid()
    {
        // Charge la vue pour le prepaid
        return view('prepaid', ['title' => 'Prepaid']);
    }

    public function dashboard()
    {
        // Si l'utilisateur est authentifié, la logique d'authentification a déjà été gérée
        return view('dashboard', ['title' => 'Tableau de Bord']);
    }

    public function profile()
    {
        // Si l'utilisateur est authentifié, la logique d'authentification a déjà été gérée
        return view('parameters/profile', ['title' => 'Profil']);
    }

    public function users()
    {
        // Si l'utilisateur est authentifié, la logique d'authentification a déjà été gérée
        return view('parameters/users', ['title' => 'Utilisateurs']);
    }
}