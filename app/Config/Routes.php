<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// $routes->group('', ['filter' => 'autologout'], function ($routes) {

//     $routes->get('dashboard', 'DashboardController::index');
//     $routes->get('profile', 'UserController::profile');
//     $routes->post('facture/create', 'FactureController::create');

// });

// Dashboard
$routes->get('/', function () {return redirect()->to(site_url('postpaid/particulier'));});

// Authentification
$routes->get('authentification/login', 'Views\AuthentificationController::login'); 
$routes->post('authentification/login', 'Views\AuthentificationController::doLogin');
$routes->get('authentification/logout', 'Views\AuthentificationController::logout');
$routes->get('dashboard', 'Views\DisplayViewsController::dashboard');
$routes->get('administration/audit', 'AuditController::index', ['filter' => 'auditadmin']);
$routes->get('administration/audit/export/(:segment)', 'AuditController::export/$1', ['filter' => 'auditadmin']);


// Affichage formulaire
$routes->get('postpaid/particulier', 'Views\DisplayViewsController::particulier');
$routes->get('postpaid/general', 'Views\DisplayViewsController::general');
$routes->get('postpaid/etat', 'Views\DisplayViewsController::etat');
$routes->get('prepaid', 'Views\DisplayViewsController::prepaid');


// Grouper les routes POST sensibles derrière le filtre d'authentification
$routes->group('', ['filter' => ['autologout', 'importmanager']], static function($routes){
	$routes->post('postpaid/particulier', 'MemoryController::importAndExport');
	$routes->post('postpaid/general', 'MemoryController::importAndExport');
	$routes->post('postpaid/etat', 'MemoryController::importAndExport');
	$routes->post('prepaid', 'MemoryController::importAndExport');
});


// Paramètres
$routes->get('parameters/profile', 'Views\DisplayViewsController::profile');
$routes->get('parameters/users', 'Views\DisplayViewsController::users');


// =====================
// HEALTH / TEST
// =====================
