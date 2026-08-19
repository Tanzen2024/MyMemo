<?php 
// footer.php sécurisé avec spinner global bloquant mais non destructif
?>

<!-- Footer -->
<footer class="main-footer">
    <strong>LAMAT &copy; 2026</strong>
</footer>
</div> <!-- fermeture wrapper -->

<!-- jQuery et plugins -->
<script src="<?= base_url('assets/adminlte/plugins/jquery/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/adminlte/dist/js/adminlte.min.js') ?>"></script>
<script src="<?= base_url('assets/jQuery/postpaid_particulier.Form.js') ?>"></script>

<?= $this->renderSection('content') ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.10.0/js/bootstrap-datepicker.min.js"></script>

<style>
/* Spinner overlay global */
#overlay-spinner {
    display: none;
    position: fixed;
    top:0; left:0;
    width:100%; height:100%;
    background: rgba(255,255,255,0.85);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    flex-direction: column;
}

#overlay-spinner .spinner-border {
    width: 4rem;
    height: 4rem;
}

#overlay-spinner-text {
    margin-top: 1rem;
    font-size: 1.2rem;
}
</style>

<!-- Spinner overlay -->
<div id="overlay-spinner">
    <div class="spinner-container">

        <div class="spinner-border text-primary" role="status"></div>

        <div id="overlay-spinner-text">
            Veuillez patienter… <span id="spinner-percent">0</span> %
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){

    const types = ['postpaid_general', 'postpaid_particulier', 'prepaid', 'postpaid_etat'];
    const now = new Date();
    const currentCycle = now.getMonth() === 0 ? 12 : now.getMonth();

    types.forEach(type => {

        // Récupération des éléments
        const startInput = document.getElementById(`date_start_${type}`);
        const endInput   = document.getElementById(`date_end_${type}`);
        const cycleInput = document.getElementById(`cycle_${type}`);
        const yearInput  = document.getElementById(`year_${type}`);

        const contratRadio  = document.getElementById(`ref_contrat_${type}`);
        const factureRadio  = document.getElementById(`ref_facture_${type}`);
        const emissionRadio = document.getElementById(`ref_emission_${type}`);
        const impayesRadio  = document.getElementById(`ref_impayes_${type}`);

        if (!startInput || !endInput || !cycleInput || !yearInput) return;

        // Stocker le type dans les inputs pour y accéder facilement
        startInput.dataset.type = type;
        endInput.dataset.type   = type;

        // Initialisation cycle et année
        cycleInput.value = currentCycle;
        yearInput.value  = now.getFullYear();

        // ===== Fonctions utilitaires =====
        const pad = num => String(num).padStart(2,'0');

        function getLastDayOfMonth(year, month){
            return new Date(year, month, 0).getDate();
        }

        function getDateDebut(annee, cycle, type) { 
            if (type === 'postpaid_etat' || type === 'prepaid') {
                return `01/${pad(cycle)}/${annee}`;
            } else {
                mois = cycle;
                return `05/${pad(cycle)}/${annee}`;
            }
        }

        function getDateFin(annee, cycle, type) { 
            if (type === 'postpaid_etat' || type === 'prepaid') { 
                let lastDay = getLastDayOfMonth(annee, cycle);
                return `${lastDay}/${pad(cycle)}/${annee}`;
            } else { 
                cycle++; 
                if (cycle > 12) { 
                    cycle = 1; 
                    annee++; 
                } 
                return `04/${pad(cycle)}/${annee}`; 
            } 
        }

        // ===== Datepicker jQuery =====
        $(startInput).datepicker({
            format: 'dd/mm/yyyy',
            autoclose: true,
            todayHighlight: true,
            weekStart: 1,
            language: 'fr',
            todayBtn: "linked"
        }).on('changeDate', e => $(endInput).datepicker('setStartDate', e.date));

        $(endInput).datepicker({
            format: 'dd/mm/yyyy',
            autoclose: true,
            todayHighlight: true,
            weekStart: 1,
            language: 'fr',
            todayBtn: "linked"
        }).on('changeDate', e => $(startInput).datepicker('setEndDate', e.date));

        // ===== Fonction de mise à jour des dates =====
        function updateDates(){
            // Si facture est sélectionnée, ne rien remplir
            const isFacture = factureRadio && factureRadio.checked;
            if (isFacture) return;

            startInput.disabled = false;
            endInput.disabled = false;

            // ===== Cas spécial impayés =====
            const isImpayes = (impayesRadio && impayesRadio.checked) &&
                            (type === 'postpaid_general' || type === 'postpaid_particulier');

            if(isImpayes){
                // dateDebut = dateFin = date d'hier
                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                const day   = String(yesterday.getDate()).padStart(2,'0');
                const month = String(yesterday.getMonth() + 1).padStart(2,'0'); // mois = 0..11
                const year  = yesterday.getFullYear();
                const formatted = `${day}/${month}/${year}`;
                startInput.value = formatted;
                endInput.value   = formatted;
            } else {
                // date normale
                startInput.value = getDateDebut(parseInt(yearInput.value), parseInt(cycleInput.value), startInput.dataset.type);
                endInput.value   = getDateFin(parseInt(yearInput.value), parseInt(cycleInput.value), endInput.dataset.type);
            }

            startInput.style.opacity = "1";
            endInput.style.opacity = "1";
        }

        // ===== Fonction de toggle générale =====
        function toggleControls() {
            const isFacture = factureRadio && factureRadio.checked;

            // Désactiver / activer radios emission / impayés
            if(emissionRadio && impayesRadio){
                emissionRadio.disabled = isFacture;
                impayesRadio.disabled  = isFacture;
                emissionRadio.checked = isFacture ? false : true;
                impayesRadio.checked  = isFacture ? false : impayesRadio.checked;

                emissionRadio.closest('.option-item').style.opacity = isFacture ? 0.5 : 1;
                impayesRadio.closest('.option-item').style.opacity  = isFacture ? 0.5 : 1;
            }

            // Désactiver / activer les champs date
            if (isFacture) {
                startInput.value = '';
                endInput.value = '';
                startInput.placeholder = 'dd/mm/yyyy';
                endInput.placeholder = 'dd/mm/yyyy';
                startInput.disabled = true;
                endInput.disabled = true;
                startInput.style.opacity = "0.5";
                endInput.style.opacity = "0.5";
            } else {
                // Sinon remplir les dates normalement
                updateDates();
            }
        }

        // ===== Écouteurs =====
        if(contratRadio) contratRadio.addEventListener('change', toggleControls);
        if(factureRadio) factureRadio.addEventListener('change', toggleControls);
        if(emissionRadio) emissionRadio.addEventListener('change', updateDates);
        if(impayesRadio)  impayesRadio.addEventListener('change', updateDates);
        cycleInput.addEventListener('input', updateDates);
        yearInput.addEventListener('input', updateDates);

        // Initialisation
        toggleControls();

    }); // fin foreach types


    // ================================
    // SPINNER GLOBAL FORMULAIRE
    // ================================
    // Uniquement les formulaires de génération Postpaid/Prepaid (réponse =
    // fichier à télécharger). Sans ce filtre, TOUT formulaire de TOUTE page
    // (recherche, filtres, CRUD Gestion des utilisateurs...) était intercepté
    // et sa navigation/redirection normale absorbée par ce fetch().
    document.querySelectorAll('form[data-async="true"]').forEach(form => {

    form.addEventListener("submit", async function(e){
        e.preventDefault();

        const overlay = document.getElementById('overlay-spinner');
        const percentEl = document.getElementById('spinner-percent');

        const btn = form.querySelector("button[type='submit'], input[type='submit']");
        if(btn) btn.disabled = true;

        overlay.style.display = 'flex';

        let percent = 0;

        const interval = setInterval(() => {
            percent += Math.random() * 5;
            if(percent > 95) percent = 95;
            percentEl.textContent = Math.floor(percent);
        }, 120);

        try {

            const formData = new FormData(form);

            const response = await fetch(form.action, {
                method: form.method,
                body: formData
            });

            // 🔴 GESTION ERREUR SERVEUR
            if (!response.ok) {

                clearInterval(interval);
                overlay.style.display = 'none';
                if(btn) btn.disabled = false;

                let errorText = "Erreur serveur";

                try {
                    const err = await response.json();
                    errorText = err.error || errorText;
                } catch (e) {
                    const text = await response.text();
                    console.error(text);
                }

                alert(errorText);
                return;
            }

            // 🔵 RÉCUPÉRATION NOM FICHIER
            const disposition = response.headers.get('Content-Disposition');

            let filename = "export.xlsx";

            if (disposition) {
                const match = disposition.match(/filename\*?=UTF-8''([^;]+)|filename="?([^"]+)"?/i);

                filename = match?.[1] || match?.[2] || filename;
            }

            // 🔵 DOWNLOAD BLOB
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);

            const a = document.createElement("a");
            a.href = url;
            a.download = filename;

            document.body.appendChild(a);
            a.click();
            a.remove();

            URL.revokeObjectURL(url);

            // 🔵 FIN PROGRESS BAR
            clearInterval(interval);
            percentEl.textContent = "100";

            setTimeout(() => {
                overlay.style.display = 'none';
                if(btn) btn.disabled = false;
            }, 400);

        } catch (e) {

            console.error(e);

            clearInterval(interval);
            overlay.style.display = 'none';

            if(btn) btn.disabled = false;

            alert("Erreur lors de la génération du fichier");
        }

    });

});

});
</script>

<!-- ===============================
     DÉCONNEXION : confirmation + état de chargement
=============================== -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-sign-out-alt"></i> Déconnexion</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Êtes-vous sûr de vouloir vous déconnecter ?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-danger" id="logoutConfirmBtn">
          <i class="fas fa-sign-out-alt"></i> <span class="logout-btn-label">Déconnexion</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let logoutHref = null;

    document.querySelectorAll('.logout-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            logoutHref = this.getAttribute('href');
            $('#logoutConfirmModal').modal('show');
        });
    });

    const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');
    if (logoutConfirmBtn) {
        logoutConfirmBtn.addEventListener('click', function () {
            if (!logoutHref) return;
            logoutConfirmBtn.disabled = true;
            logoutConfirmBtn.classList.add('is-loading');
            logoutConfirmBtn.querySelector('.logout-btn-label').textContent = 'Déconnexion…';
            window.location.href = logoutHref;
        });
    }
});
</script>


</body>
</html>