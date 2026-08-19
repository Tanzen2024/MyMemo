<?= $this->include('templates/header') ?>
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?= site_url('administration/users') ?>" class="nav-link">Gestion des utilisateurs</a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto">
    <li class="nav-item">
      <a class="nav-link logout-link" href="<?= site_url('authentification/logout') ?>">
        <i class="fas fa-sign-out-alt"></i> Déconnexion
      </a>
    </li>
  </ul>
</nav>
<div class="content-wrapper"><section class="content pt-3"><div class="container-fluid">

<?php if (session('msg')): ?>
  <div class="alert alert-warning"><?= esc(session('msg')) ?></div>
<?php endif; ?>

<div class="card" style="max-width:520px">
<div class="card-header"><h3 class="card-title"><?= $mode === 'create' ? 'Ajouter un utilisateur MyMemo' : 'Modifier ' . esc($user['username']) ?></h3></div>
<div class="card-body">
<form method="post" action="<?= $mode === 'create' ? site_url('administration/users') : site_url('administration/users/' . urlencode($user['username'])) ?>">
  <?= csrf_field() ?>

  <div class="form-group">
    <label>Identifiant AD (login, sans @domaine)</label>
    <?php if ($mode === 'create'): ?>
      <input class="form-control" name="username" value="<?= esc(old('username')) ?>" required pattern="[a-zA-Z0-9._-]{1,64}" autofocus>
      <small class="form-text text-muted">Doit correspondre à un compte AD existant. Ceci ne crée ni compte AD, ni mot de passe.</small>
    <?php else: ?>
      <input class="form-control" value="<?= esc($user['username']) ?>" disabled>
      <small class="form-text text-muted">L'identifiant AD est stable et ne peut pas être modifié ici.</small>
    <?php endif; ?>
  </div>

  <div class="form-group">
    <label>Nom affiché</label>
    <input class="form-control" name="display_name" maxlength="190" value="<?= esc(old('display_name') ?? $user['display_name']) ?>">
  </div>

  <div class="form-group">
    <label>Rôles</label>
    <?php $currentRoles = old('roles') ?? $user['roles']; ?>
    <?php foreach ($knownRoles as $role): ?>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= esc($role) ?>" id="role-<?= esc($role) ?>" <?= in_array($role, $currentRoles, true) ? 'checked' : '' ?>>
        <label class="form-check-label" for="role-<?= esc($role) ?>"><?= esc($role) ?></label>
      </div>
    <?php endforeach ?>
  </div>

  <div class="form-group form-check">
    <input class="form-check-input" type="checkbox" name="enabled" id="enabled" <?= $user['enabled'] ? 'checked' : '' ?>>
    <label class="form-check-label" for="enabled">Actif</label>
  </div>

  <button class="btn btn-primary"><?= $mode === 'create' ? 'Ajouter' : 'Enregistrer' ?></button>
  <a class="btn btn-outline-secondary" href="<?= site_url('administration/users') ?>">Annuler</a>
</form>
</div></div>

</div></section></div><?= $this->include('templates/footer') ?>
