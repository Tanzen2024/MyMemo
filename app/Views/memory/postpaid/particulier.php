<?= $this->include('templates/header') ?>

<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?= site_url('postpaid/particulier') ?>" class="nav-link active">Postpaid - Particulier</a>
    </li>
  </ul>

  <!-- <ul class="navbar-nav ml-auto">
    <li class="nav-item">
      <a class="nav-link" href="<?= site_url('authentification/logout') ?>">
        <i class="fas fa-sign-out-alt"></i> Déconnexion
      </a>
    </li>
  </ul> -->
</nav>

<div class="content-wrapper">
  <section class="content">
    <div class="container d-flex justify-content-center">
      <form data-context="js_postpaid_particulier" id="FormPostpaidParticulier" method="post" data-async="true" action="<?= site_url('postpaid/particulier') ?>" class="w-75" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <!-- CONTEXTE DU FORMULAIRE -->
      <input type="hidden" name="referentiel_context" value="postpaid_particulier">
      
        <!-- ===============================
             INFORMATIONS GÉNÉRALES
        =============================== -->
        <div class="card shadow-sm mb-3">
          <div class="card-header" style="background-color: #3498db; color: white;"><h5>Informations générales</h5></div>
          <div class="card-body row">
            <div class="col-md-6 mb-3">
            <label>Année</label>
            <input type="number" id="year_postpaid_particulier" name="year_postpaid_particulier" class="form-control" value="<?= date('Y') ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label>Cycle</label>
                <input type="number" id="cycle_postpaid_particulier" name="cycle_postpaid_particulier" class="form-control" min="1" max="12" required>
            </div>
          </div>
        </div>

        <!-- ===============================
             CLIENT
        =============================== -->
        <div class="card shadow-sm mb-3">
          <div class="card-header" style="background-color: #2ecc71; color: white;"><h5>Client</h5></div>
          <div class="card-body">
            <input type="text" name="regroupName_postpaid_particulier" class="form-control" placeholder="Nom du client" required>
          </div>
        </div>

       
            <!-- ===============================
             RÉFÉRENTIEL & ÉMISSION
        =============================== -->
<div class="card shadow-sm mb-3">
  <div class="card-header bg-gradient-info text-white">
    <h5>Référentiel & Émission</h5>
  </div>
  <div class="card-body row justify-content-center">

            <div class="card-body">
              <!-- Flex horizontal centré -->
              <div class="d-flex justify-content-center" style="gap: 2rem; flex-wrap: nowrap;">

                <!-- Groupe de boutons pour "Contrat" et "Facture" -->
                <div style="border: 1px solid #d3d3d3; border-radius: 5px; padding: 1rem; margin-right: 1rem;">
                  <div class="option-item">
                    <input type="radio"
                          name="radio_referentiel_type_postpaid_particulier"
                          id="ref_contrat_postpaid_particulier"
                          value="contrat"
                          checked>
                    <label for="ref_contrat_postpaid_particulier" class="option-pill btn btn-outline-success">
                      <i class="fa fa-handshake"></i>
                      <span>Sur contrats</span>
                    </label>
                  </div>

                  <!-- Facture -->
                  <div class="option-item">
                    <input type="radio"
                          name="radio_referentiel_type_postpaid_particulier"
                          id="ref_facture_postpaid_particulier"
                          value="facture">
                    <label for="ref_facture_postpaid_particulier" class="option-pill btn btn-outline-warning">
                      <i class="fas fa-file-invoice"></i>
                      <span>Sur factures</span>
                    </label>
                  </div>
                </div>

                <!-- Groupe de boutons pour "Émission" et "Impayés" -->
                <div style="border: 1px solid #d3d3d3; border-radius: 5px; padding: 1rem;">
                  <!-- Émission -->
                  <div class="option-item">
                    <input type="radio"
                          name="radio_referentiel_type_postpaid_particulier_emission"
                          id="ref_emission_postpaid_particulier"
                          value="emission"
                          checked>
                    <label for="ref_emission_postpaid_particulier" class="option-pill btn btn-outline-primary">
                      <i class="fas fa-bolt"></i>
                      <span>Émission</span>
                    </label>
                  </div>

                  <!-- Impayés -->
                  <div class="option-item">
                    <input type="radio"
                          name="radio_referentiel_type_postpaid_particulier_emission"
                          id="ref_impayes_postpaid_particulier"
                          value="impayes">
                    <label for="ref_impayes_postpaid_particulier" class="option-pill btn btn-outline-info">
                      <i class="fas fa-plug"></i>
                      <span>Impayés</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>


         <!-- ===============================
             IMPORT EXCEL
        =============================== -->
        <div class="card shadow-sm mb-3">
          <div class="card-header" style="background-color: #8e44ad; color: white;"><h5>Importer le référentiel</h5></div>
          <div class="card-body">
            <input type="file" name="referentiel_file_postpaid_particulier" class="form-control" accept=".xls,.xlsx" required>
            <small class="text-muted">Excel accepté</small>
          </div>
        </div>


        <!-- Dates -->
        <div class="card card-warning mb-3 shadow-sm animate__animated animate__fadeIn">
          <div class="card-header"><h5>Période</h5></div>
          <div class="card-body row">

            <!-- Date début -->
            <div class="col-md-6 mb-3">
              <label for="date_start_postpaid_particulier">Date début</label>
              <div class="input-group">
                <input type="text" id="date_start_postpaid_particulier" name="date_start_postpaid_particulier"
                      class="form-control datepicker" placeholder="dd/mm/yyyy" required>
                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
              </div>
            </div>

            <!-- Date fin -->
            <div class="col-md-6 mb-3">
              <label for="date_end_postpaid_particulier">Date fin</label>
              <div class="input-group">
                <input type="text" id="date_end_postpaid_particulier" name="date_end_postpaid_particulier"
                      class="form-control datepicker" placeholder="dd/mm/yyyy" required>
                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
              </div>
            </div>

          </div>
        </div>


        <!-- ===============================
             DOCUMENT
        =============================== -->
        <div class="card shadow-sm mb-4">
          <div class="card-header" style="background-color: #808080; color: white;"><h5>Document</h5></div>  <!-- Couleur grise -->
          <div class="card-body">
            <!-- Flex horizontal centré -->
            <div class="d-flex justify-content-center" style="gap: 2rem; flex-wrap: nowrap;">

              <!-- Mémoires -->
              <div class="option-item">
                <input type="checkbox"
                      name="document_type_postpaid_particulier[]"
                      id="memoires"
                      value="memoires"
                      checked>
                <label for="memoires" class="option-pill btn btn-outline-success">
                  <i class="fa fa-handshake"></i>&nbsp;Mémoires
                </label>
              </div>

              <!-- Données Mémoires -->
              <div class="option-item">
                <input type="checkbox"
                      name="document_type_postpaid_particulier[]"
                      id="donnees_memoires"
                      value="donnees_memoires">
                <label for="donnees_memoires" class="option-pill btn btn-outline-warning">
                  <i class="fa fa-file-invoice"></i>&nbsp;Données Mémoires
                </label>
              </div>
            </div>
          </div>
        </div>

        <button name="btnSubmitPostpaidparticulier" type="submit" class="btn btn-primary btn-lg w-100">Valider</button>
      </form>
    </div>
  </section>
</div>

<!-- ===============================
     POPUP RAPPORT
=============================== -->
<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-gradient-info text-white">
        <h5 class="modal-title" id="reportModalLabel">Rapport d'importation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="reportContent">
        <!-- Le rapport sera injecté ici -->
      </div>
      <div class="modal-footer">
        <button id="btnSubmitPostpaidparticulier" type="button" class="btn btn-primary" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<?= $this->include('templates/footer') ?>
