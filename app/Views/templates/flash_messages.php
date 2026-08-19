<?php if (session('msg') || session('gestReturnInfo')): ?>
  <div class="container-fluid pt-3">
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <button type="button" class="close" data-dismiss="alert" aria-label="Fermer">&times;</button>
      <?= esc(session('msg') ?? session('gestReturnInfo')) ?>
    </div>
  </div>
<?php endif; ?>
