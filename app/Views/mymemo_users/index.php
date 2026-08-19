<?= $this->include('templates/header') ?>
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?= site_url('administration/users') ?>" class="nav-link active"><?= lang('Users.pageTitle') ?></a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto">
    <?= $this->include('templates/topbar_actions') ?>
    <li class="nav-item">
      <a class="nav-link logout-link" href="<?= site_url('authentification/logout') ?>">
        <i class="fas fa-sign-out-alt"></i> <?= lang('App.logout') ?>
      </a>
    </li>
  </ul>
</nav>
<div class="content-wrapper"><section class="content pt-3"><div class="container-fluid">

<?php if (session('msg')): ?>
  <div class="alert alert-info"><?= esc(session('msg')) ?></div>
<?php endif; ?>

<?php if (! empty($readError)): ?>
  <div class="alert alert-danger"><?= esc($readError) ?></div>
<?php endif; ?>

<div class="alert alert-secondary">
  <?= lang('Users.infoBanner') ?>
</div>

<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
  <h3 class="card-title"><i class="fas fa-users"></i> <?= lang('Users.pageTitle') ?></h3>
  <a class="btn btn-primary btn-sm" href="<?= site_url('administration/users/create') ?>"><i class="fas fa-plus"></i> <?= lang('App.add') ?></a>
</div><div class="card-body">
<form class="row" method="get">
  <div class="col-auto mb-2"><input class="form-control" name="search" placeholder="<?= esc(lang('Users.searchPlaceholder'), 'attr') ?>" value="<?= esc($search) ?>"></div>
  <div class="col-auto mb-2">
    <button class="btn btn-primary"><?= lang('App.search') ?></button>
    <a class="btn btn-outline-secondary" href="<?= site_url('administration/users') ?>"><?= lang('App.reset') ?></a>
  </div>
</form><hr>
<table id="usersTable" class="table table-bordered table-hover">
  <thead><tr><th><?= lang('Users.colLogin') ?></th><th><?= lang('Users.colName') ?></th><th><?= lang('Users.colRoles') ?></th><th><?= lang('App.active') ?></th><th><?= lang('App.actions') ?></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= esc($u['username']) ?></td>
      <td><?= esc($u['display_name']) ?></td>
      <td>
        <?php foreach ($u['roles'] as $role): ?>
          <span class="badge badge-<?= $role === 'ADMIN' ? 'primary' : 'info' ?>"><?= esc($role) ?></span>
        <?php endforeach ?>
        <?php if ($u['roles'] === []): ?><span class="badge badge-danger"><?= lang('Users.noRoles') ?></span><?php endif ?>
      </td>
      <td><span class="badge badge-<?= $u['enabled'] ? 'success' : 'secondary' ?>"><?= $u['enabled'] ? lang('App.yes') : lang('App.no') ?></span></td>
      <td class="text-nowrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('administration/users/' . urlencode($u['username']) . '/edit') ?>"><?= lang('App.edit') ?></a>
        <form class="d-inline" method="post" action="<?= site_url('administration/users/' . urlencode($u['username']) . '/' . ($u['enabled'] ? 'disable' : 'enable')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-<?= $u['enabled'] ? 'warning' : 'success' ?> btn-sm"><?= $u['enabled'] ? lang('App.disable') : lang('App.enable') ?></button>
        </form>
        <form class="d-inline delete-user-form" method="post" action="<?= site_url('administration/users/' . urlencode($u['username']) . '/delete') ?>" data-username="<?= esc($u['username'], 'attr') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-outline-danger btn-sm"><?= lang('App.delete') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (empty($users)): ?>
    <tr><td colspan="5" class="text-center text-muted"><?= lang('Users.noUsers') ?></td></tr>
  <?php endif ?>
  </tbody>
</table>
</div></div></div></section></div>

<!-- Confirmation de suppression (remplace la boîte de dialogue native du
     navigateur, même principe que #logoutConfirmModal dans templates/footer.php) -->
<div class="modal fade" id="deleteUserConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= lang('Users.deleteConfirmTitle') ?></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="<?= esc(lang('App.close'), 'attr') ?>"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <?php [$mmDeleteBefore, $mmDeleteAfter] = explode('%s', lang('Users.deleteConfirmBody'), 2) + ['', '']; ?>
        <p class="mb-1"><?= esc($mmDeleteBefore) ?><strong id="deleteUserConfirmName"></strong><?= esc($mmDeleteAfter) ?></p>
        <p class="text-muted mb-0"><?= lang('Users.deleteConfirmWarning') ?></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal"><?= lang('App.cancel') ?></button>
        <button type="button" class="btn btn-danger" id="deleteUserConfirmBtn"><?= lang('App.delete') ?></button>
      </div>
    </div>
  </div>
</div>

<?= $this->include('templates/footer') ?>
<link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') ?>"><script src="<?= base_url('assets/adminlte/plugins/datatables/jquery.dataTables.min.js') ?>"></script><script src="<?= base_url('assets/adminlte/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') ?>"></script>
<script>
$(function(){
  $('#usersTable').DataTable({pageLength:25,lengthMenu:[[25,50,100,-1],[25,50,100,'Tous']],order:[[0,'asc']],columnDefs:[{orderable:false,targets:4}]});

  let pendingDeleteForm = null;
  document.querySelectorAll('.delete-user-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      pendingDeleteForm = form;
      document.getElementById('deleteUserConfirmName').textContent = form.dataset.username || '';
      $('#deleteUserConfirmModal').modal('show');
    });
  });
  document.getElementById('deleteUserConfirmBtn').addEventListener('click', function () {
    if (pendingDeleteForm) {
      // form.submit() ne redéclenche pas l'écouteur 'submit' ci-dessus
      // (comportement standard du DOM) : pas de boucle, soumission normale.
      pendingDeleteForm.submit();
    }
  });
});
</script>
