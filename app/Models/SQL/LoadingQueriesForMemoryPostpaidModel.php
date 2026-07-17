<?php

namespace App\Models\SQL;

use App\DTO\DateRange;

class LoadingQueriesForMemoryPostpaidModel
{
    /* ================= OUTILS ================= */

    protected static function esc(string $v): string
    {
        return str_replace("'", "''", trim($v));
    }

    protected static function date(\DateTime $d): string
    {
        return "TO_DATE('".$d->format('Y-m-d')."', 'YYYY-MM-DD')";
    }
    

    protected static function validateDates(
    int $year,
    int $cycle,
    string $regroup,
    ?string $dateDebut,
    ?string $dateFin
): DateRange
{
    // CAS FACTURE ou sans filtre date
    if (empty($dateDebut) || empty($dateFin)) {
        return new DateRange(null, null);
    }

    $start = \DateTime::createFromFormat('d/m/Y', $dateDebut);
    $end   = \DateTime::createFromFormat('d/m/Y', $dateFin);

    if (!$start || !$end) {
        throw new \InvalidArgumentException(
            "Dates invalides : dateDebut={$dateDebut}, dateFin={$dateFin}"
        );
    }

    if ($start > $end) {
        throw new \RuntimeException(
            "La date de début ne peut pas être supérieure à la date de fin"
        );
    }

    return new DateRange($start, $end);
}

    public static function createDateCondition(
        int $year,
        int $cycle,
        string $regroupName,
        string $dateDebut,
        string $dateFin
    ): array {
        $logger = \Config\Services::logger();

        $dateRange = self::validateDates($year, $cycle, $regroupName, $dateDebut, $dateFin);

        $start = $dateRange->start;
        $end   = $dateRange->end;

        $r = service('request');

        $context = $r->getPost('referentiel_context');

        $binds = [
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
        ];

        switch ($context) {
            case 'postpaid_particulier':
                $typeGeneral  = $_POST['radio_referentiel_type_postpaid_particulier'] ?? null;
                $typeEmission = $_POST['radio_referentiel_type_postpaid_particulier_emission'] ?? null;

                $logger->info("Type général : " . ($typeGeneral ?? 'NULL'));
                $logger->info("Type émission : " . ($typeEmission ?? 'NULL'));
                $logger->info("Date début: $dateDebut, Date fin: $dateFin");

                if ($typeGeneral === 'facture') {
                    $logger->info("Retour vide (Facture sélectionné)");
                    return ['sql' => '', 'binds' => []];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'emission') {
                    return [
                        'sql' => "AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'impayes') {
                    if ($start == $end) {
                        return [
                            'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta <= TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                            'binds' => ['dateFin' => $dateFin],
                        ];
                    }

                    return [
                        'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }

                $logger->info("Retour vide par défaut");
                return ['sql' => '', 'binds' => []];

            case 'postpaid_general': // si vous aviez une autre valeur distincte
                $typeGeneral  = $_POST['radio_referentiel_type_postpaid_general'] ?? null;
                $typeEmission = $_POST['radio_referentiel_type_postpaid_general_emission'] ?? null;

                $logger->info("Type général : " . ($typeGeneral ?? 'NULL'));
                $logger->info("Type émission : " . ($typeEmission ?? 'NULL'));
                $logger->info("Date début: $dateDebut, Date fin: $dateFin");

                if ($typeGeneral === 'facture') {
                    $logger->info("Retour vide (Facture sélectionné)");
                    return ['sql' => '', 'binds' => []];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'emission') {
                    return [
                        'sql' => "AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'impayes') {
                    if ($start == $end) {
                        return [
                            'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta <= TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                            'binds' => ['dateFin' => $dateFin],
                        ];
                    }

                    return [
                        'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }

                $logger->info("Retour vide par défaut");
                return ['sql' => '', 'binds' => []];

            case 'postpaid_etat':
                $typeGeneral  = $_POST['radio_referentiel_type_postpaid_etat'] ?? null;
                $typeEmission = $_POST['radio_referentiel_type_postpaid_etat_emission'] ?? null;

                if ($typeGeneral === 'facture') {
                    $logger->info("Retour vide (Facture sélectionné)");
                    return ['sql' => '', 'binds' => []];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'emission') {
                    return [
                        'sql' => "AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }

                if ($typeGeneral === 'contrat' && $typeEmission === 'impayes') {
                    if ($start == $end) {
                        return [
                            'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta <= TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                            'binds' => ['dateFin' => $dateFin],
                        ];
                    }

                    return [
                        'sql' => "AND r.imp_tot_rec - r.imp_cta > 0 AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'YYYY-MM-DD') AND TO_DATE(:dateFin:, 'YYYY-MM-DD')",
                        'binds' => $binds,
                    ];
                }
        }

        $logger->info("Retour vide par défaut");
        return ['sql' => '', 'binds' => []];
    }


    /* ================= CHARGEMENT FACTURES PARTICULIER ================= */

    public static function LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_PARTICULIER(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin) : array 
    {
        $dateCondition = self::createDateCondition($year, $cycle, $regroupName, $dateDebut, $dateFin);

        return [
            'sql' => "
                INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_memoires t (
                    SELECT /*+ PARALLEL(8) */ DISTINCT
                        'CENTRALIZED LV' AS region,
                        s.nom_area AS cms_region,
                        s.nom_unicom,
                        :year: AS calendar_year,
                        :cycle: AS reading_cycle,
                        :regroupName: AS regroup_id,
                        :regroupName: AS regroup_name,
                        r.nis_rad,
                        NVL(TRIM(s.ape1_cli), TRIM(s.cust_name)) AS cust_name,
                        s.pk_cust_id,
                        s.meter_no,
                        DECODE(s.tip_fase, 'FA001', 2, 4) AS number_wires,
                        s.pot_max_admis,
                        sum(case when not regexp_like(codigo(i.co_concepto), 'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE') then i.csmo_fact else 0 end) over(partition by r.num_rec) csmo_total_fact,
                        r.num_rec,
                        r.f_fact,
                        r.f_prev_puesta,
                        r.imp_tot_rec,
                        r.imp_tot_rec - r.imp_cta AS due_amount,
                        r.est_act,
                        s.tip_contr,
                        r.ind_conversion,
                        r.cod_cta_pago,
                        r.num_rec_anul,
                        s.cod_cnae,
                        s.co_an_vip,
                        sum (case when i.co_concepto not like 'CT%' then i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:) amount_without_vat,
                        trunc ((sum (case when i.co_concepto not like 'CT%' then -i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:)) + r.imp_tot_rec) amount_vat,
                        sum (case when i.co_concepto in ('CC119', 'CC118') then i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:) govern_cont_without_vat,
                        sum (case when i.co_concepto = 'CT841' then i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:) govern_vat,
                        sum (case when i.co_concepto = 'CC101' then i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:) meter_rent
                    FROM cmsreport.tmp_ref_confacresu t
                    JOIN cmsadmin.recibos r ON t.confacresu = r.nis_rad
                    JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
                    LEFT JOIN cmsadmin.imp_concepto i ON r.num_rec = i.num_rec
                    WHERE r.tip_rec = 'TR010' AND r.est_act NOT IN ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                        {$dateCondition['sql']}
                )
            ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
                'regroupName' => $regroupName,
            ], $dateCondition['binds'])
        ];
    }


    public static function LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_PARTICULIER(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin): array
    {
        $dateCondition = self::createDateCondition($year, $cycle, $regroupName, $dateDebut, $dateFin);

        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires_infos t (
                    SELECT /*+ parallel(12) */ DISTINCT
                        t.confacresu AS nis_rad,
                        r.num_rec,
                        rd.prev_billed_index,
                        rd.curr_billed_index,
                        NVL((MAX(ac.cte_apa) KEEP (DENSE_RANK LAST ORDER BY ac.f_lect, ac.f_actual) OVER (PARTITION BY r.num_rec)), 1) AS coeff
                    FROM cmsreport.tmp_ref_confacresu t
                        JOIN cmsadmin.recibos r ON t.confacresu = r.nis_rad
                        LEFT JOIN cmsadmin.bill_extraction_list b ON r.num_rec = b.num_rec
                        LEFT JOIN cmsadmin.rel_rec_csmo rc ON r.num_rec = rc.num_rec
                        LEFT JOIN cmsadmin.apmedida_co ac ON rc.id_apco_ant = ac.id_reg
                        LEFT JOIN cmsreport.tb_rdd_billed_index rd ON rd.num_rec = r.num_rec
                )
            ",
            'binds' => []
        ];
    }


    /* ================= MÉMOIRE AVEC FACTURE ================= */

    public static function LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_PARTICULIER(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin): array
    {
        $dateCondition = self::createDateCondition($year, $cycle, $regroupName, $dateDebut, $dateFin);

        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires t (
                    SELECT /*+ parallel(8) */ DISTINCT
                        'CENTRALIZED LV' AS region,
                        s.nom_area AS cms_region,
                        s.nom_unicom,
                        :year: AS calendar_year,
                        :cycle: AS reading_cycle,
                        :regroupName: AS regroup_id,
                        :regroupName: AS regroup_name,
                        r.nis_rad,
                        NVL(TRIM(s.ape1_cli), TRIM(s.cust_name)) AS cust_name,
                        s.pk_cust_id,
                        s.meter_no,
                        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
                        s.pot_max_admis,
                        sum(case when not regexp_like(codigo(i.co_concepto), 'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE') then i.csmo_fact else 0 end) over(partition by r.num_rec) csmo_total_fact,
                        r.num_rec,
                        r.f_fact,
                        r.f_prev_puesta,
                        r.imp_tot_rec,
                        r.imp_tot_rec - r.imp_cta AS due_amount,
                        r.est_act,
                        s.tip_contr,
                        r.ind_conversion,
                        r.cod_cta_pago,
                        r.num_rec_anul,
                        s.cod_cnae,
                        s.co_an_vip,
                        sum (case when i.co_concepto not like 'CT%' then i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:) amount_without_vat,
                        trunc ((sum (case when i.co_concepto not like 'CT%' then -i.imp_concepto else 0 end) over(partition by r.num_rec, :regroupName:)) + r.imp_tot_rec) amount_vat,
                        sum (case when i.co_concepto in ('CC119', 'CC118') then i.imp_concepto else 0 end) over (partition by r.num_rec, :regroupName:) govern_cont_without_vat,
                        sum (case when i.co_concepto = 'CT841' then i.imp_concepto else 0 end) over (partition by r.num_rec, :regroupName:) govern_vat,
                        sum (case when i.co_concepto = 'CC101' then i.imp_concepto else 0 end) over (partition by r.num_rec, :regroupName:) meter_rent
                    FROM cmsreport.tmp_ref_confacresu t
                        JOIN cmsadmin.recibos r ON t.confacresu = r.num_rec
                        JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
                        LEFT JOIN cmsadmin.imp_concepto i ON r.num_rec = i.num_rec
                    WHERE r.tip_rec = 'TR010' AND r.est_act NOT IN ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                        {$dateCondition['sql']}
                )
            ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
                'regroupName' => $regroupName,
            ], $dateCondition['binds'])
        ];
    }

    public static function LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_PARTICULIER(): array
    {
        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires_infos t (
                    SELECT /*+ parallel(12) */ DISTINCT
                        t.confacresu AS nis_rad,
                        r.num_rec,
                        rd.prev_billed_index,
                        rd.curr_billed_index,
                        NVL((MAX(ac.cte_apa) KEEP (DENSE_RANK LAST ORDER BY ac.f_lect, ac.f_actual) OVER (PARTITION BY r.num_rec)), 1) AS coeff
                    FROM cmsreport.tmp_ref_confacresu t
                        JOIN cmsadmin.recibos r ON t.confacresu = r.num_rec
                        LEFT JOIN cmsadmin.bill_extraction_list b ON r.num_rec = b.num_rec
                        LEFT JOIN cmsadmin.rel_rec_csmo rc ON r.num_rec = rc.num_rec
                        LEFT JOIN cmsadmin.apmedida_co ac ON rc.id_apco_ant = ac.id_reg
                        LEFT JOIN cmsreport.tb_rdd_billed_index rd ON rd.num_rec = r.num_rec
                )
            ",
            'binds' => []
        ];
    }




    /* ================= CHARGEMENT FACTURES PARTICULIER ================= */

    public static function LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin) : array 
    {
        $dateCondition = self::createDateCondition($year, $cycle, $regroupName, $dateDebut, $dateFin);

        return [
            'sql' => "
                INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_memoires t (
                    SELECT /*+ PARALLEL(8) */ DISTINCT
                        'CENTRALIZED LV' AS region,
                        s.nom_area AS cms_region,
                        s.nom_unicom,
                        :year: AS calendar_year,
                        :cycle: AS reading_cycle,
                        t.tutelle as regroup_id,
                        t.tutelle as regroup_name,
                        r.nis_rad,
                        NVL(TRIM(s.ape1_cli), TRIM(s.cust_name)) AS cust_name,
                        s.pk_cust_id,
                        s.meter_no,
                        DECODE(s.tip_fase, 'FA001', 2, 4) AS number_wires,
                        s.pot_max_admis,
                        sum(case when not regexp_like(codigo(i.co_concepto), 'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE') then i.csmo_fact else 0 end) over(partition by r.num_rec) csmo_total_fact,
                        r.num_rec,
                        r.f_fact,
                        r.f_prev_puesta,
                        r.imp_tot_rec,
                        r.imp_tot_rec - r.imp_cta AS due_amount,
                        r.est_act,
                        s.tip_contr,
                        r.ind_conversion,
                        r.cod_cta_pago,
                        r.num_rec_anul,
                        s.cod_cnae,
                        s.co_an_vip,
                        sum (case when i.co_concepto not like 'CT%' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) amount_without_vat,
                        trunc ((sum (case when i.co_concepto not like 'CT%' then -i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle)) + r.imp_tot_rec) amount_vat,
                        sum (case when i.co_concepto in ('CC119', 'CC118') then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) govern_cont_without_vat,
                        sum (case when i.co_concepto = 'CT841' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) govern_vat,
                        sum (case when i.co_concepto = 'CC101' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) meter_rent
                    FROM cmsreport.tmp_ref_etat_with_contrat t
                    JOIN cmsadmin.recibos r ON t.contrat = r.nis_rad
                    JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
                    LEFT JOIN cmsadmin.imp_concepto i ON r.num_rec = i.num_rec
                    WHERE r.tip_rec = 'TR010'
                        AND r.est_act NOT IN ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                        {$dateCondition['sql']}
                )
            ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
            ], $dateCondition['binds'])
        ];
    }


    public static function LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin): array
    {
        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires_infos t (
                    select /*+  parallel(12) */ distinct
                        t.contrat AS nis_rad,
                        r.num_rec,
                        nvl2(trim(b.prev_reading), b.prev_reading, nvl((max (ap.lect) keep(dense_rank last order by ap.f_lect, ap.f_actual) over(partition by r.num_rec)), 0)) prev_actual_read,
                        nvl2(trim(b.curr_reading), b.curr_reading, nvl((max (ac.lect) keep(dense_rank last order by ac.f_lect, ac.f_actual) over(partition by r.num_rec)), 0)) hht_current_index,
                        nvl((max (ac.cte_apa) keep(dense_rank last order by ac.f_lect, ac.f_actual) over(partition by r.num_rec)), 1) coeff
                        from cmsreport.tmp_ref_etat_with_contrat t
                        join cmsadmin.recibos r on t.contrat = r.nis_rad
                        left join cmsadmin.bill_extraction_list b on r.num_rec = b.num_rec
                        left join cmsadmin.rel_rec_csmo rc on r.num_rec = rc.num_rec
                        left join cmsadmin.apmedida_co ap on rc.id_apco_ant = ap.id_reg
                        left join cmsadmin.apmedida_co ac on rc.id_apco_act = ac.id_reg
                        where r.tip_rec = 'TR010' and r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600') and r.imp_tot_rec - r.imp_cta > 0 
                        and r.f_prev_puesta <= sysdate
                )
            ",
            'binds' => []
        ];
    }


    /* ================= MÉMOIRE AVEC FACTURE ================= */

    public static function LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL(int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin): array
    {
        $dateCondition = self::createDateCondition($year, $cycle, $regroupName, $dateDebut, $dateFin);

        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires t (
                    SELECT /*+ parallel(8) */ DISTINCT
                        'CENTRALIZED LV' AS region,
                        s.nom_area AS cms_region,
                        s.nom_unicom,
                        :year: AS calendar_year,
                        :cycle: AS reading_cycle,
                        t.tutelle as regroup_id,
                        t.tutelle as regroup_name,
                        r.nis_rad,
                        NVL(TRIM(s.ape1_cli), TRIM(s.cust_name)) AS cust_name,
                        s.pk_cust_id,
                        s.meter_no,
                        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
                        s.pot_max_admis,
                        sum(case when not regexp_like(codigo(i.co_concepto), 'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE') then i.csmo_fact else 0 end) over(partition by r.num_rec) csmo_total_fact,
                        r.num_rec,
                        r.f_fact,
                        r.f_prev_puesta,
                        r.imp_tot_rec,
                        r.imp_tot_rec - r.imp_cta AS due_amount,
                        r.est_act,
                        s.tip_contr,
                        r.ind_conversion,
                        r.cod_cta_pago,
                        r.num_rec_anul,
                        s.cod_cnae,
                        s.co_an_vip,
                        sum (case when i.co_concepto not like 'CT%' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) amount_without_vat,
                        trunc ((sum (case when i.co_concepto not like 'CT%' then -i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle)) + r.imp_tot_rec) amount_vat,
                        sum (case when i.co_concepto in ('CC119', 'CC118') then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) govern_cont_without_vat,
                        sum (case when i.co_concepto = 'CT841' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) govern_vat,
                        sum (case when i.co_concepto = 'CC101' then i.imp_concepto else 0 end) over(partition by r.num_rec,t.tutelle) meter_rent
                    FROM cmsreport.tmp_ref_etat_with_contrat t
                        JOIN cmsadmin.recibos r ON t.contrat = r.num_rec
                        JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
                        LEFT JOIN cmsadmin.imp_concepto i ON r.num_rec = i.num_rec
                    WHERE r.tip_rec = 'TR010' AND r.est_act NOT IN ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                        {$dateCondition['sql']}
                )
            ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
            ], $dateCondition['binds'])
        ];
    }

    public static function LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL(): array
    {
        return [
            'sql' => "
                INSERT /*+ append parallel(t) */ INTO cmsreport.tb_factures_memoires_infos t (

                    select /*+  parallel(12) */ distinct
                        r.nis_rad,
                        t.contrat AS num_rec,
                        nvl2(trim(b.prev_reading), b.prev_reading, nvl((max (ap.lect) keep(dense_rank last order by ap.f_lect, ap.f_actual) over(partition by r.num_rec)), 0)) prev_actual_read,
                        nvl2(trim(b.curr_reading), b.curr_reading, nvl((max (ac.lect) keep(dense_rank last order by ac.f_lect, ac.f_actual) over(partition by r.num_rec)), 0)) hht_current_index,
                        nvl((max (ac.cte_apa) keep(dense_rank last order by ac.f_lect, ac.f_actual) over(partition by r.num_rec)), 1) coeff
                        from cmsreport.tmp_ref_etat_with_contrat t
                        join cmsadmin.recibos r on t.contrat = r.num_rec
                        left join cmsadmin.bill_extraction_list b on r.num_rec = b.num_rec
                        left join cmsadmin.rel_rec_csmo rc on r.num_rec = rc.num_rec
                        left join cmsadmin.apmedida_co ap on rc.id_apco_ant = ap.id_reg
                        left join cmsadmin.apmedida_co ac on rc.id_apco_act = ac.id_reg
                        where r.tip_rec = 'TR010' and r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600') and r.imp_tot_rec - r.imp_cta > 0 
                        and r.f_prev_puesta <= sysdate
                )
            ",
            'binds' => []
        ];
    }




    /* ================= DONNÉES MÉMOIRE & MÉMOIRES ================= */

    public static function AGENTS_CDE_CAMWATER(): string
    {
        return "
            select /*+  parallel(8) */ distinct
                t.type_client region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                t.tutelle regroup_code,
                t.tutelle regroup_name,
                r.nis_rad contrat,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                r.number_wires,
                r.pot_max_admis subscription_load,

                max(trunc (r.csmo_total_fact)) keep(dense_rank last order by r.f_fact) over(partition by r.num_rec,t.type_client) consumption_billed,

                r.num_rec bill_no,
                to_char(r.f_fact,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                r.due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,

                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,

                c.prev_actual_read,
                c.hht_current_index,
                c.coeff,
                r.cod_cta_pago
            from (select /*+ parallel(2) */ * from cmsreport.tmp_ref_etat_with_contrat where regexp_like(tutelle || ' ' || tutelle,'CDE|CAMWATER') and regexp_like(tutelle || tutelle,'AGENT')) t
                join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
                join cmsreport.tb_factures_memoires a on t.contrat = a.nis_rad and r.num_rec_anul = a.num_rec_anul and a.est_act != 'ER018' and a.cod_cta_pago not in (2000000,2000001)
                left join cmsreport.tb_factures_memoires_infos c on a.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago in (2000001, 2000000)
                and r.ind_conversion <> 1
            order by 1, 2, 7
                    ";
    }

    public static function BUREAUX_CDE_CAMWATER(): string
    {
        return "
            select /*+  parallel(8) */ distinct
                t.type_client region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                (case when regexp_like(nvl(t.nom_regroup, t.regroup_name),'IHS.+CAM') then 'IHS CAMEROON' else nvl(t.cod_regroup,t.regroup_id) end) regroup_code,
                nvl(t.tutelle, t.tutelle) regroup_name,
                r.nis_rad contrat,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                r.number_wires,
                r.pot_max_admis subscription_load,

                trunc (r.csmo_total_fact) consumption_billed,
                r.num_rec bill_no,
                to_char(r.f_fact,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                r.due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,

                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,

                c.prev_actual_read,
                c.hht_current_index,
                c.coeff
            from (select /*+ parallel(2) */ * from cmsreport.tmp_ref_etat_with_contrat where regexp_like(tutelle || ' ' || tutelle,'CDE|CAMWATER') and not regexp_like(tutelle || tutelle,'AGENT')) t
                join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
                left join cmsreport.tb_factures_memoires_infos c on r.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.ind_conversion <> 1
            order by 1, 2, 7
        ";
    }


    public static function AGENTS_GLOBELEQ () : string
    {
        return "
            select /*+  parallel(8) */ distinct
                t.type_client region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                (case when regexp_like(nvl(t.tutelle, t.tutelle),'IHS.+CAM') then 'IHS CAMEROON' else nvl(t.tutelle,t.tutelle) end) regroup_code,
                nvl(t.tutelle, t.tutelle) regroup_name,
                r.nis_rad contrat,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                r.number_wires,
                r.pot_max_admis subscription_load,

                trunc (r.csmo_total_fact) consumption_billed,
                r.num_rec bill_no,
                to_char(r.f_fact,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                r.due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,

                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,

                c.prev_actual_read,
                c.hht_current_index,
                c.coeff
            from (select /*+ parallel(2) */ * from cmsreport.tmp_ref_etat_with_contrat where regexp_like(tutelle || ' ' || tutelle,'GLOBELEQ|DPDC|KPDC') and not regexp_like(tutelle || tutelle,'AGENT')) t
                join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
                left join cmsreport.tb_factures_memoires_infos c on r.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.ind_conversion <> 1
            order by 1, 2, 7
    ";
    }


    public static function BUREAUX_GLOBELEQ () : string
    {
        return "
            select /*+  parallel(8) */ distinct
            t.type_client region,
            r.nom_unicom agency,
            r.calendar_year,
            r.reading_cycle,
            t.tutelle regroup_code,
            t.tutelle regroup_name,
            r.nis_rad contrat,
            r.cust_name customer_name,
            r.pk_cust_id old_account_no,
            r.meter_no,
            r.number_wires,
            r.pot_max_admis subscription_load,

            max(trunc (r.csmo_total_fact)) keep(dense_rank last order by r.f_fact) over(partition by r.num_rec,t.type_client) consumption_billed,

            r.num_rec bill_no,
            to_char(r.f_fact,'dd/mm/yyyy') billing_date,
            to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
            trunc (r.imp_tot_rec) amount_with_tax,
            r.due_amount,
            estado(r.est_act) bill_status,
            substr (tipo(r.tip_contr), 1, 2) cat_cli,

            r.amount_without_vat,
            r.amount_vat,
            r.govern_cont_without_vat,
            r.govern_vat,
            r.meter_rent,

            c.prev_actual_read,
            c.hht_current_index,
            c.coeff
        ,r.cod_cta_pago
        from (select /*+ parallel(2) */ * from cmsreport.tmp_ref_etat_with_contrat where regexp_like(cod_regroup || ' ' || nom_regroup,'GLOBELEQ|DPDC|KPDC') ) t
            join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
            join cmsreport.tb_factures_memoires a on t.contrat = a.nis_rad and r.num_rec_anul = a.num_rec_anul and a.est_act != 'ER018' and a.cod_cta_pago not in (2000000,2000001)
            left join cmsreport.tb_factures_memoires_infos c on a.num_rec = c.num_rec
        where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
            and r.cod_cta_pago in (2000001, 2000000)
            and r.ind_conversion <> 1
        order by 1, 2, 7
            ";
    }


    public static function GRANDS_COMPTES () : string
    {
        return "
            WITH tb_referentiel AS (
                SELECT 
                    co.contrat,
                    co.tutelle AS cod_regroup,
                    co.tutelle AS nom_regroup,
                    CASE WHEN i.volt_tp_id = 'LV' THEN 'STATE LV' ELSE 'STATE MV' END AS type_client,
                    i.nom_area AS region,
                    i.tip_fase
                FROM cmsreport.tmp_ref_etat_with_contrat co
                JOIN cmsreport.tb_customers_infos i ON co.contrat = i.nis_rad
            )
            ,
            tb_liste_contrats_memoires as (
            select /*+ parallel(2) */
                *
            from tb_referentiel
            minus
            (
            select /*+ parallel(2) */
                *
            from tb_referentiel
            where regexp_like(cod_regroup || ' ' || nom_regroup,'CDE|CAMWATER')
                /*and regexp_like(cod_regroup || nom_regroup,'AGENT')*/
            union
            select /*+ parallel(2) */
                *
            from tb_referentiel
            where type_client = 'STATE LV'
            ))
            select /*+  parallel(8) */ distinct
                t.type_client region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                t.cod_regroup regroup_code,
                t.nom_regroup regroup_name,
                r.nis_rad contrat,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                decode(t.tip_fase,'FA001', 2, 4) number_wires,
                r.pot_max_admis subscription_load,
                max(trunc (r.csmo_total_fact)) keep(dense_rank last order by r.f_fact) over(partition by r.num_rec) consumption_billed,
                r.num_rec bill_no,
                to_char(r.f_fact,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                r.due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,
                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,
                c.prev_actual_read,
                c.hht_current_index,
                c.coeff
            from tb_liste_contrats_memoires t
                join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
                join cmsreport.tb_factures_memoires a on t.contrat = a.nis_rad and r.num_rec_anul = a.num_rec_anul and a.est_act != 'ER018' and a.cod_cta_pago not in (2000000,2000001)
                left join cmsreport.tb_factures_memoires_infos c on a.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago in (2000001, 2000000)
                and r.ind_conversion <> 1
            union
            select /*+  parallel(8) */ distinct
                t.type_client region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                t.cod_regroup regroup_code,
                t.nom_regroup regroup_name,
                r.nis_rad contrat,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                decode(t.tip_fase,'FA001', 2, 4) number_wires,
                r.pot_max_admis subscription_load,
                trunc (r.csmo_total_fact) consumption_billed,
                r.num_rec bill_no,
                to_char(r.f_fact,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                r.due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,
                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,
                c.prev_actual_read,
                c.hht_current_index,
                c.coeff
            from tb_liste_contrats_memoires t
                join cmsreport.tb_factures_memoires r on t.contrat = r.nis_rad
                left join cmsreport.tb_factures_memoires_infos c on r.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago not in (2000001, 2000000)
                and r.ind_conversion <> 1
            order by 1, 2, 7
                ";
     }


    public static function generateDatasMemoire(): string
    {
        return "
            SELECT /*+ parallel(8) */ DISTINCT
                r.region,
                r.nom_unicom AS agency,
                r.calendar_year,
                r.reading_cycle,
                r.regroup_id AS regroup_code,
                r.regroup_name,
                r.nis_rad AS service_no,
                r.cust_name AS customer_name,
                r.pk_cust_id AS old_account_no,
                r.meter_no,
                r.number_wires,
                r.pot_max_admis AS subscription_load,
                TRUNC(r.csmo_total_fact) AS consumption_billed,
                r.num_rec AS bill_no,
                TO_CHAR(r.f_fact, 'dd/mm/yyyy') AS billing_date,
                TO_CHAR(r.f_prev_puesta, 'dd/mm/yyyy') AS dispatch_date,
                TRUNC(r.imp_tot_rec) AS amount_with_tax,
                r.due_amount,
                estado(r.est_act) AS bill_status,
                SUBSTR(tipo(r.tip_contr), 1, 2) AS cat_cli,
                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,
                c.prev_actual_read,
                c.hht_current_index,
                c.coeff
            FROM cmsreport.tb_factures_memoires r
            LEFT JOIN cmsreport.tb_factures_memoires_infos c 
                ON r.num_rec = c.num_rec
            ORDER BY r.region, r.nom_unicom, r.nis_rad
        ";
    }


    public static function generateMemoire(): string
    {
        return "
            SELECT /*+ parallel(8) */ DISTINCT
                r.cust_name AS customer_name,
                r.nom_unicom AS agency,
                r.nis_rad AS service_no,
                TO_CHAR(r.f_fact, 'dd/mm/yyyy') AS billing_date,
                r.num_rec AS bill_no,
                r.meter_no,
                c.prev_actual_read,
                c.hht_current_index,
                TRUNC(r.csmo_total_fact) AS consumption_billed,
                c.coeff,
                r.meter_rent,
                r.amount_without_vat,
                r.amount_vat,
                TRUNC(r.imp_tot_rec) AS amount_with_tax,
                r.due_amount,        
                r.region,
                r.calendar_year,
                r.reading_cycle,
                r.regroup_id,
                r.regroup_name              
            FROM cmsreport.tb_factures_memoires r
            LEFT JOIN cmsreport.tb_factures_memoires_infos c ON r.num_rec = c.num_rec
        ";
    }
}