<?= $this->include('templates/header') ?>

<!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="<?= site_url('prepaid') ?>" class="nav-link active"><?= lang('Menu.prepaid') ?></a>
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

<div class="content-wrapper">
  <?= $this->include('templates/flash_messages') ?>
  <section class="content">
    <div class="container d-flex justify-content-center">
      <form id="FormPrepaid" method="post" action="<?= site_url('prepaid') ?>" class="w-75" data-async="true" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="referentiel_context" value="prepaid">
        <!-- ===============================
             INFORMATIONS GÉNÉRALES
        =============================== -->
        <div class="card card-primary mb-3 shadow-sm">
          <div class="card-header"><h5><?= lang('Memoire.generalInfo') ?></h5></div>
          <div class="card-body row">
            <div class="col-md-6 mb-3">
              <label for="year_prepaid"><?= lang('Memoire.year') ?></label>
              <input type="number" id="year_prepaid" name="year_prepaid" class="form-control" value="<?= date('Y') ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label for="cycle_prepaid"><?= lang('Memoire.cycle') ?></label>
              <input type="number" id="cycle_prepaid" name="cycle_prepaid" class="form-control" min="1" max="12" required>
            </div>
          </div>
        </div>

        <!-- ===============================
             CLIENT
        =============================== -->
        <div class="card card-secondary mb-3 shadow-sm">
          <div class="card-header"><h5><?= lang('Memoire.client') ?></h5></div>
          <div class="card-body">
            <input type="text" name="regroupName_prepaid" class="form-control" placeholder="<?= esc(lang('Memoire.clientNamePlaceholder'), 'attr') ?>" required>
          </div>
        </div>

             <!-- ===============================
                RÉFÉRENTIEL
        ================================= -->
        <div class="card card-info mb-4 card-soft">
          <div class="card-header">
            <h5 class="header-left"><?= lang('Memoire.referential') ?></h5>
          </div>

          <div class="card-body">
            <!-- Flex horizontal centré -->
            <div class="d-flex justify-content-center" style="gap: 2rem; flex-wrap: nowrap;">

              <!-- Sur contrats -->
              <div class="option-item">
                <input type="radio"
                      name="radio_referentiel_type_prepaid"
                      id="ref_contrat_prepaid"
                      value="contrat"
                      checked>
                <label for="ref_contrat_prepaid" class="option-pill btn btn-outline-success">
                  <i class="fa fa-handshake"></i>&nbsp;<?= lang('Memoire.onContracts') ?>
                </label>
              </div>

              <!-- Sur reçus -->
              <div class="option-item">
                <input type="radio"
                      name="radio_referentiel_type_prepaid"
                      id="ref_facture_prepaid"
                      value="facture">
                <label for="ref_facture_prepaid" class="option-pill btn btn-outline-warning">
                  <i class="fa fa-file-invoice"></i>&nbsp;<?= lang('Memoire.onReceipts') ?>
                </label>
              </div>

            </div>
          </div>
        </div>


        <!-- ===============================
             IMPORT
        =============================== -->
        <div class="card card-success mb-3 shadow-sm">
          <div class="card-header"><h5><?= lang('Memoire.importReferential') ?></h5></div>
          <div class="card-body">
            <input type="file" name="referentiel_file_prepaid" class="form-control">
            <small class="text-muted"><?= lang('Memoire.excelAccepted') ?></small>
          </div>
        </div>


         <!-- ===============================
             PÉRIODE
        =============================== -->
       <div class="card card-warning mb-3 shadow-sm animate__animated animate__fadeIn">
        <div class="card-header"><h5><?= lang('Memoire.period') ?></h5></div>
        <div class="card-body row">

          <!-- Date début -->
          <div class="col-md-6 mb-3">
            <label for="date_start_prepaid"><?= lang('Memoire.dateStart') ?></label>
            <div class="input-group">
              <input type="text" id="date_start_prepaid" name="date_start_prepaid"
                    class="form-control datepicker" placeholder="dd/mm/yyyy" required>
              <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            </div>
          </div>

          <!-- Date fin -->
          <div class="col-md-6 mb-3">
            <label for="date_end_prepaid"><?= lang('Memoire.dateEnd') ?></label>
            <div class="input-group">
              <input type="text" id="date_end_prepaid" name="date_end_prepaid"
                    class="form-control datepicker" placeholder="dd/mm/yyyy" required>
              <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
            </div>
          </div>
        </div>
      </div>

        <!-- ===============================
             DOCUMENT
        =============================== -->
        <div class="card card-info mb-4 card-soft">
          <div class="card-header">
            <h5 class="header-left"><?= lang('Memoire.document') ?></h5>
          </div>

          <div class="card-body">
            <!-- Flex horizontal centré -->
            <div class="d-flex justify-content-center" style="gap: 2rem; flex-wrap: nowrap;">

              <!-- Mémoires -->
              <div class="option-item">
                <input type="checkbox"
                      name="document_type_prepaid[]"
                      id="memoires"
                      value="memoires"
                      checked>
                <label for="memoires" class="option-pill btn btn-outline-success">
                  <i class="fa fa-handshake"></i>&nbsp;<?= lang('Memoire.memos') ?>
                </label>
              </div>

              <!-- Données Mémoires -->
              <div class="option-item">
                <input type="checkbox"
                      name="document_type_prepaid[]"
                      id="donnees_memoires"
                      value="donnees_memoires">
                <label for="donnees_memoires" class="option-pill btn btn-outline-warning">
                  <i class="fa fa-file-invoice"></i>&nbsp;<?= lang('Memoire.memoData') ?>
                </label>
              </div>
            </div>
          </div>
        </div>

        <button id="btnSubmitPrepaid" type="submit" class="btn btn-primary btn-lg w-100">
          <?= lang('Memoire.submit') ?>
        </button>

      </form>
    </div>
  </section>
</div>

<?= $this->include('templates/footer') ?>
