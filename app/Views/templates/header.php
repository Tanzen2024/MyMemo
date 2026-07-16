<?php
$uri = service('uri');
$currentPath = $uri->getPath();

function isActive($urlSegment, $currentPath) {
    return strpos($currentPath, $urlSegment) !== false ? 'active' : '';
}

function isMenuOpen($urlSegment, $currentPath) {
    return strpos($currentPath, $urlSegment) !== false ? 'menu-open' : '';
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
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.nav-sidebar .nav-link { border-radius: 10px; margin: 2px 6px; transition: all 0.2s ease; }
.nav-sidebar .nav-link:hover { background: rgba(255,255,255,0.08); transform: translateX(2px); }
.nav-sidebar .nav-link.active,
.nav-sidebar .nav-link.handover { background: rgba(0,123,255,0.15); border-left: 4px solid #007bff; font-weight: 600; }
.nav-sidebar .nav-treeview { margin-left: 14px; padding-left: 10px; border-left: 1px dashed rgba(255,255,255,0.15); }
.nav-sidebar .nav-treeview .nav-link { font-size: 0.92rem; }
.nav-sidebar .nav-treeview .nav-treeview .nav-link { font-size: 0.88rem; }
.nav-sidebar .nav-icon { width: 20px; text-align: center; opacity: 0.9; }
.nav-sidebar .right { transition: transform .25s ease; }
.nav-sidebar .menu-open > a .right { transform: rotate(-90deg); }
</style>
</head>

<body class="hold-transition sidebar-mini">
  
<div class="wrapper">

<!-- <div id="overlay-spinner" style="
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background: rgba(255,255,255,0.5);
    z-index:9999;
    align-items:center;
    justify-content:center;
    flex-direction: column;
    text-align: center;
">
    <div style="
        border:8px solid #f3f3f3;
        border-top:8px solid #007bff;
        border-radius:50%;
        width:70px;
        height:70px;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    "></div>
    <div id="overlay-spinner-text" style="font-weight:5000; color:#007bff; font-size:1.2rem;">
        Chargement… 0 %
    </div>
</div>

<style>
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
} -->

<style>
input:disabled,
select:disabled,
textarea:disabled,
button:disabled {
    background-color: #e9ecef !important;
    color: #6c757d !important;
    border-color: #ced4da !important;
    cursor: not-allowed;
    opacity: 0.6;
}
</style>

<!-- Sidebar -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <a href="<?=  site_url('postpaid/particulier') ?>" class="brand-link">
    <span class="brand-text font-weight-light">MyMemo</span>
  </a>
  <div class="sidebar">
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">

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

            <li class="nav-item has-treeview <?= isMenuOpen('administration', $currentPath) ?>"><a href="#" class="nav-link <?= isActive('administration', $currentPath) ?>"><i class="nav-icon fas fa-user-shield"></i><p>Administration<i class="right fas fa-angle-left"></i></p></a><ul class="nav nav-treeview"><li class="nav-item"><a href="<?= site_url('administration/audit') ?>" class="nav-link <?= isActive('administration/audit', $currentPath) ?>"><i class="nav-icon fas fa-clipboard-list"></i><p>Journal d'audit</p></a></li></ul></li>

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
