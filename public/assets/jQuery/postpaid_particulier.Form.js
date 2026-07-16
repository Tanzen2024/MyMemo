$(function () {

    const $form = $('form[data-context="js_postpaid_particulier"]');
    if ($form.length === 0) {
        return; // formulaire non présent
    }

    const $referentielRadios = $form.find('input[name="radio_referentiel_type_postpaid_particulier"]');
    const $emissionRadios    = $form.find('input[name="radio_emission_postpaid_particulier"]');
    const $dateStart         = $form.find('input[name="date_start_postpaid_particulier"]');
    const $dateEnd           = $form.find('input[name="date_end_postpaid_particulier"]');

    function setEmissionAndDates(enabled, reset = false) {

        $emissionRadios.prop('disabled', !enabled);

        $dateStart.prop('disabled', !enabled);
        $dateEnd.prop('disabled', !enabled);

        if (reset) {
            $emissionRadios.prop('checked', false);
            $dateStart.val('');
            $dateEnd.val('');
        }
    }

    /* ======================================================
       INITIALISATION — clic menu Postpaid / Particulier
       => CONTRAT + EMISSION par défaut
    ====================================================== */

    // Sélection automatique "contrat"
    $referentielRadios
        .filter('[value="contrat"]')
        .prop('checked', true);

    // Activer émission + dates
    setEmissionAndDates(true);

    // Sélection automatique émission "fraiche" si existe
    const $defaultEmission = $emissionRadios.filter('[value="fraiche"]');
    if ($defaultEmission.length) {
        $defaultEmission.prop('checked', true);
    } else {
        // fallback : première émission
        $emissionRadios.first().prop('checked', true);
    }

    /* ======================================================
       CHANGEMENT DU RÉFÉRENTIEL
    ====================================================== */
    $referentielRadios.on('change', function () {

        if (this.value === 'facture') {
            // FACTURE → tout désactivé + reset
            setEmissionAndDates(false, true);
            return;
        }

        if (this.value === 'contrat') {
            // CONTRAT → tout activé
            setEmissionAndDates(true);

            // Si aucune émission cochée, en sélectionner une
            if ($emissionRadios.filter(':checked').length === 0) {
                $emissionRadios.filter('[value="fraiche"]').first().prop('checked', true);
            }
        }
    });

});
