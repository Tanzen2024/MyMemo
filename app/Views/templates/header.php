<?php
$uri = service('uri');
$currentPath = $uri->getPath();

// function_exists() : ce template peut être inclus plusieurs fois dans le
// même processus PHP (tests unitaires) ; sans cette garde, une deuxième
// inclusion provoquerait une erreur fatale de redéclaration.
if (! function_exists('isActive')) {
    function isActive($urlSegment, $currentPath) {
        return strpos($currentPath, $urlSegment) !== false ? 'active' : '';
    }
}

if (! function_exists('isMenuOpen')) {
    function isMenuOpen($urlSegment, $currentPath) {
        return strpos($currentPath, $urlSegment) !== false ? 'menu-open' : '';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyMemo Dashboard</title>
<link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/fontawesome-free/css/all.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/adminlte/dist/css/adminlte.min.css') ?>">
<link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.10.0/css/bootstrap-datepicker.min.css">
<link rel="stylesheet" href="<?= base_url('assets/css/soft-ui.css') ?>">
</head>

<body class="hold-transition sidebar-mini">
  
<div class="wrapper">

<!-- Sidebar -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <a href="<?=  site_url('postpaid/particulier') ?>" class="brand-link">
    <span class="brand-text font-weight-light">MyMemo</span>
  </a>
  <div class="sidebar">
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">

            <!-- Dashboard -->
            <li class="nav-item">
              <a href="<?= site_url('dashboard') ?>" class="nav-link <?= isActive('dashboard', $currentPath) ?>">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <p>Dashboard</p>
              </a>
            </li>

            <!-- Postpaid -->
            <li class="nav-item has-treeview <?= isMenuOpen('postpaid', $currentPath) ?>">
              <a href="#" class="nav-link <?= isActive('postpaid', $currentPath) ?>">
                <i class="nav-icon fas fa-file-contract"></i>
                <p>
                  Postpaid
                  <i class="right fas fa-angle-left"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">
                <li class="nav-item">
                  <a href="<?= site_url('postpaid/particulier') ?>" class="nav-link <?= isActive('postpaid/particulier', $currentPath) ?>">
                    <i class="nav-icon fas fa-user"></i>
                    <p>Particulier</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= site_url('postpaid/general') ?>" class="nav-link <?= isActive('postpaid/general', $currentPath) ?>">
                    <i class="nav-icon fas fa-globe"></i>
                    <p>Général</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="<?= site_url('postpaid/etat') ?>" class="nav-link <?= isActive('postpaid/etat', $currentPath) ?>">
                    <i class="nav-icon fas fa-landmark"></i>
                    <p>État</p>
                  </a>
                </li>
              </ul>
            </li>

            <!-- Prepaid -->
            <li class="nav-item">
              <a href="<?= site_url('prepaid') ?>" class="nav-link <?= isActive('prepaid', $currentPath) ?>">
                <i class="nav-icon fas fa-wallet"></i>
                <p>
                  Prepaid
                </p>
              </a>
            </li>

            <?php if (session('is_mymemo_admin') === true): ?>
            <li class="nav-item has-treeview <?= isMenuOpen('administration', $currentPath) ?>"><a href="#" class="nav-link <?= isActive('administration', $currentPath) ?>"><i class="nav-icon fas fa-user-shield"></i><p>Administration<i class="right fas fa-angle-left"></i></p></a><ul class="nav nav-treeview"><li class="nav-item"><a href="<?= site_url('administration/audit') ?>" class="nav-link <?= isActive('administration/audit', $currentPath) ?>"><i class="nav-icon fas fa-clipboard-list"></i><p>Journal d'audit</p></a></li><li class="nav-item"><a href="<?= site_url('administration/users') ?>" class="nav-link <?= isActive('administration/users', $currentPath) ?>"><i class="nav-icon fas fa-users"></i><p>Gestion des utilisateurs</p></a></li></ul></li>
            <?php endif; ?>

          </ul>
        </li>
      </ul>
    </nav>
  </div>
</aside>

<!-- JS simple pour surbrillance hover -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.nav-sidebar .nav-link').forEach(link => {
    link.addEventListener('mouseenter', () => link.classList.add('handover'));
    link.addEventListener('mouseleave', () => {
      if (!link.classList.contains('active')) {
        link.classList.remove('handover');
      }
    });
  });
});
</script>
