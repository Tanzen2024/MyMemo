<?php

namespace App\Models\SQL;

class LoadingQueriesForMemoryEtatModel
{
 

/* BT */
 
 public static function LOADING_TB_FACTURES_ETAT_BASE_READINGS() : string
    {
        return "
            insert /*+ append parallel(t) */ into cmsreport.tb_factures_etat_base_readings t (
                select
                        t.*,
                    dense_rank() over(partition by bill_no, reading_type  order by last_reading_date desc, id_apa) meters_order
                from (
                select /*+  parallel(8) */ distinct
                        t.nis_rad,
                        ap.num_sum,
                        t.num_rec bill_no,
                        ap.id_apa,
                        ap.id_reg,
                        ap.lect,
                        ap.csmo,
                        ap.cte_apa,
                        ap.f_lect,
                        ap.f_actual,
                        count(distinct ap.id_reg) over(partition by t.num_rec) nber_of_readings,
                        row_number() over(partition by t.num_rec order by ap.f_lect desc, ap.f_actual desc, ap.id_reg desc) readings_order,
                        max(ap.f_lect) over(partition by t.num_rec, ap.id_apa) last_reading_date,
                        t.bill_is_estimate,
                        1 reading_type
                from cmsreport.tb_factures_etat_pour_memoires t
                        left join cmsadmin.rel_rec_csmo rc on t.num_rec = rc.num_rec
                        left join cmsadmin.apmedida_co ap on rc.id_apco_ant = ap.id_reg
                union
                select /*+  parallel(8) */ distinct
                        t.nis_rad,
                        ac.num_sum,
                        t.num_rec bill_no,
                        ac.id_apa,
                        ac.id_reg,
                        ac.lect,
                        ac.csmo,
                        ac.cte_apa,
                        ac.f_lect,
                        ac.f_actual,
                        count(distinct ac.id_reg) over(partition by t.num_rec) nber_of_readings,
                        row_number() over(partition by t.num_rec order by ac.f_lect desc, ac.f_actual desc, ac.id_reg desc) readings_order,
                        max(ac.f_lect) over(partition by t.num_rec, ac.id_apa) last_reading_date,
                        t.bill_is_estimate,
                        2 reading_type
                from cmsreport.tb_factures_etat_pour_memoires t
                        left join cmsadmin.rel_rec_csmo rc on t.num_rec = rc.num_rec
                        left join cmsadmin.apmedida_co ac on rc.id_apco_act = ac.id_reg) t
            )
    ";
    }

    protected static function esc(string $v): string
    {
        return str_replace("'", "''", trim($v));
    }

    protected static function date(\DateTime $d): string
    {
        return "TO_DATE('".$d->format('Y-m-d')."', 'YYYY-MM-DD')";
    }

    protected static function createDateCondition(string $dateDebut, string $dateFin): array
    {
        [$start, $end] = self::validateDates($dateDebut, $dateFin);

        return [
            'sql' => "AND r.f_prev_puesta BETWEEN TO_DATE(:dateDebut:, 'DD/MM/YYYY') AND TO_DATE(:dateFin:, 'DD/MM/YYYY')",
            'binds' => [
                'dateDebut' => $start->format('d/m/Y'),
                'dateFin'   => $end->format('d/m/Y'),
            ],
        ];
    }

    protected static function validateDates(string $dateDebut, string $dateFin): array
    {
        $start = \DateTime::createFromFormat('d/m/Y', $dateDebut);
        $end   = \DateTime::createFromFormat('d/m/Y', $dateFin);

        if (!$start || !$end) {
            throw new \InvalidArgumentException("Dates invalides : dateDebut={$dateDebut}, dateFin={$dateFin}");
        }

        if ($start > $end) {
            throw new \RuntimeException("La date de début ne peut pas être supérieure à la date de fin.");
        }

        return [$start, $end];
    }

    public static function LOADING_TB_FACTURES_ETAT_READING_INFOS () : string 
    {
        return "
            insert /*+ append parallel(t) */ into cmsreport.tb_factures_etat_reading_infos t (
    select /*+  parallel(6) */ distinct
            nis_rad,
            num_rec,
            replace(prev_actual_read, '.E', 'E') prev_actual_read,
            replace(hht_current_index, '.E', 'E') hht_current_index,
            coeff
    from (
    with
        tb_ai_kwhs as (
            select distinct
                    ai.bill_no,
                    (max (ai.lect) keep(dense_rank last order by ai.f_lect, ai.f_actual, ai.id_reg) over(partition by ai.bill_no)) reading
            from cmsreport.tb_factures_etat_base_readings ai
            where reading_type = 1 and meters_order = 1),
        tb_ni_kwhs as (
            select distinct
                    ni.bill_no,
                    (max (ni.cte_apa) keep(dense_rank last order by ni.f_lect, ni.f_actual, ni.id_reg) over(partition by ni.bill_no)) coeff,
                    (max (ni.lect) keep(dense_rank last order by ni.f_lect, ni.f_actual, ni.id_reg) over(partition by ni.bill_no)) reading,
                    (sum (case when readings_order <> 1 then ni.csmo else 0 end) over(partition by ni.bill_no)) reading_for_ai_if_estimate,
                    (sum (ni.csmo) over(partition by ni.bill_no)) reading_if_estimate
            from cmsreport.tb_factures_etat_base_readings ni
            where reading_type = 2 and meters_order = 1)
    select /*+ parallel(6) */ distinct
            b.nis_rad,
            b.num_rec,

            replace(('' || (case
                    when b.bill_is_estimate = 1 then to_char(round((nvl(ai.reading, 0) + nvl(ni.reading_for_ai_if_estimate, 0)), 2), 'FM999999990D9999') || 'E'
                    else '' || ai.reading
            end)), ',', '.') prev_actual_read,

            replace(('' || (case
                    when b.bill_is_estimate = 1 then to_char(round((nvl(ai.reading, 0) + nvl(ni.reading_if_estimate, 0)), 2), 'FM999999990D9999') || 'E'
                    else '' || ni.reading
            end)), ',', '.') hht_current_index,

            coeff
    from cmsreport.tb_factures_etat_pour_memoires b
    left join tb_ai_kwhs ai on b.num_rec = ai.bill_no
    left join tb_ni_kwhs ni on b.num_rec = ni.bill_no
))

    ";
    }

     public static function GENERATE_DATA_MEMOIRE_BT () : string 
    {
        return "
            select /*+  parallel(8) */ distinct
                'DÉLÉGATIONS RÉGIONALES' region,
                r.nom_unicom agency,
                calendar_year,
                r.reading_cycle,
                r.regroup_id regroup_code,
                r.regroup_name,
                r.nis_rad service_no,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                r.number_wires,
                r.pot_max_admis subscription_load,
                trunc (r.csmo_total_fact) consumption_billed,
                r.num_rec bill_no,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') billing_date,
                to_char(r.f_prev_puesta,'dd/mm/yyyy') dispatch_date,
                trunc (r.imp_tot_rec) amount_with_tax,
                trunc (r.imp_tot_rec) due_amount,
                estado(r.est_act) bill_status,
                substr (tipo(r.tip_contr), 1, 2) cat_cli,

                r.amount_without_vat,
                r.amount_vat,
                r.govern_cont_without_vat,
                r.govern_vat,
                r.meter_rent,

                nvl2(c.num_rec, c.prev_billed_index, co.prev_actual_read) prev_actual_read,
                nvl2(c.num_rec, c.curr_billed_index, co.hht_current_index) hht_current_index,
                co.coeff
            from cmsreport.tb_factures_etat_pour_memoires r
                left join cmsreport.tb_rdd_billed_index c on r.num_rec = c.num_rec
                left join cmsreport.tb_factures_etat_reading_infos co on r.num_rec = co.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago not in (2000001, 2000000)
            order by 1, 2, 7
                ";  
    }


     public static function GENERATE_MEMOIRE_BT(): string
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
            FROM cmsreport.tb_factures_etat_pour_memoires r
            LEFT JOIN cmsreport.tb_factures_etat_reading_infos c ON r.num_rec = c.num_rec
        ";
    }


     public static function LOADING_TB_FACTURES_ETAT_POUR_MEMOIRES_WITH_CONTRAT (int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin) : array 
    {
        $dateCondition = self::createDateCondition($dateDebut, $dateFin);

        return [
            'sql' => "
            INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_pour_memoires t
(
    region,
    nom_unicom,
    calendar_year,
    reading_cycle,
    regroup_id,
    regroup_name,
    nis_rad,
    cust_name,
    pk_cust_id,
    meter_no,
    number_wires,
    pot_max_admis,
    csmo_total_fact,
    num_rec,
    f_fact,
    f_prev_puesta,
    imp_tot_rec,
    due_amount,
    est_act,
    tip_contr,
    ind_conversion,
    cod_cta_pago,
    num_rec_anul,
    amount_without_vat,
    amount_vat,
    govern_cont_without_vat,
    govern_vat,
    meter_rent,
    id_statement,
    bill_is_estimate
)

WITH tmp_state_bills_data AS (
    SELECT /*+ PARALLEL(8) */ DISTINCT
        s.nom_area AS region,
        s.nom_unicom,
        :year: AS calendar_year,
        :cycle: AS reading_cycle,
        t.tutelle AS regroup_id,
        t.tutelle AS regroup_name,
        t.contrat AS nis_rad,
        NVL(s.ape1_cli, s.cust_name) AS cust_name,
        s.pk_cust_id,
        s.meter_no,
        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
        s.pot_max_admis,
        r.num_rec,
        r.f_fact,
        r.f_prev_puesta,
        r.imp_tot_rec,
        (r.imp_tot_rec - r.imp_cta) AS due_amount,
        r.est_act,
        s.tip_contr,
        r.ind_conversion,
        r.cod_cta_pago,
        r.num_rec_anul,
        r.id_statement,
        DECODE(r.ind_real_est, 1, 0, 1) AS bill_is_estimate
    FROM cmsreport.tmp_ref_etat_with_contrat t
    JOIN cmsadmin.recibos r ON t.contrat = r.nis_rad
    JOIN cmsreport.tb_customers_infos s ON t.contrat = s.nis_rad
    WHERE r.tip_rec = 'TR010'
      {$dateCondition['sql']}
),

tmp_state_bills_data_infos AS (
    SELECT /*+ PARALLEL(6) */
        b.num_rec,

        SUM(
            CASE
                WHEN NOT REGEXP_LIKE(i.co_concepto,
                    'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE')
                THEN i.csmo_fact ELSE 0
            END
        ) OVER (PARTITION BY b.num_rec) AS csmo_total_fact,

        SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_without_vat,

        SUM(CASE WHEN i.co_concepto LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_vat,

        SUM(CASE WHEN i.co_concepto IN ('CC119','CC118') THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_cont_without_vat,

        SUM(CASE WHEN i.co_concepto = 'CT841' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_vat,

        SUM(CASE WHEN i.co_concepto = 'CC101' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS meter_rent

    FROM tmp_state_bills_data b
    LEFT JOIN cmsadmin.imp_concepto i
        ON b.num_rec = i.num_rec
)

SELECT /*+ PARALLEL(6) */ DISTINCT
    b.region,
    b.nom_unicom,
    b.calendar_year,
    b.reading_cycle,
    b.regroup_id,
    b.regroup_name,
    b.nis_rad,
    b.cust_name,
    b.pk_cust_id,
    b.meter_no,
    b.number_wires,
    b.pot_max_admis,
    i.csmo_total_fact,
    b.num_rec,
    b.f_fact,
    b.f_prev_puesta,
    b.imp_tot_rec,
    b.due_amount,
    b.est_act,
    b.tip_contr,
    b.ind_conversion,
    b.cod_cta_pago,
    b.num_rec_anul,
    i.amount_without_vat,
    i.amount_vat,
    i.govern_cont_without_vat,
    i.govern_vat,
    i.meter_rent,
    b.id_statement,
    b.bill_is_estimate
FROM tmp_state_bills_data b
LEFT JOIN tmp_state_bills_data_infos i
    ON b.num_rec = i.num_rec
        ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
            ], $dateCondition['binds'])
        ];
        }

     public static function LOADING_TB_FACTURES_ETAT_POUR_MEMOIRES_WITH_FACTURE (int $year, int $cycle, string $regroupName, string $dateDebut, string $dateFin) : array 
    {
        $dateCondition = self::createDateCondition($dateDebut, $dateFin);

        return [
            'sql' => "
            INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_pour_memoires t
(
    region,
    nom_unicom,
    calendar_year,
    reading_cycle,
    regroup_id,
    regroup_name,
    nis_rad,
    cust_name,
    pk_cust_id,
    meter_no,
    number_wires,
    pot_max_admis,
    csmo_total_fact,
    num_rec,
    f_fact,
    f_prev_puesta,
    imp_tot_rec,
    due_amount,
    est_act,
    tip_contr,
    ind_conversion,
    cod_cta_pago,
    num_rec_anul,
    amount_without_vat,
    amount_vat,
    govern_cont_without_vat,
    govern_vat,
    meter_rent,
    id_statement,
    bill_is_estimate
)

WITH tmp_state_bills_data AS (
    SELECT /*+ PARALLEL(8) */ DISTINCT
        s.nom_area AS region,
        s.nom_unicom,
        :year: AS calendar_year,
        :cycle: AS reading_cycle,
        t.tutelle AS regroup_id,
        t.tutelle AS regroup_name,
        r.nis_rad,
        NVL(s.ape1_cli, s.cust_name) AS cust_name,
        s.pk_cust_id,
        s.meter_no,
        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
        s.pot_max_admis,
        t.contrat as num_rec,
        r.f_fact,
        r.f_prev_puesta,
        r.imp_tot_rec,
        (r.imp_tot_rec - r.imp_cta) AS due_amount,
        r.est_act,
        s.tip_contr,
        r.ind_conversion,
        r.cod_cta_pago,
        r.num_rec_anul,
        r.id_statement,
        DECODE(r.ind_real_est, 1, 0, 1) AS bill_is_estimate
    FROM cmsreport.tmp_ref_etat_with_contrat t
    JOIN cmsadmin.recibos r ON t.contrat = r.num_rec
    JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
    WHERE r.tip_rec = 'TR010'
      {$dateCondition['sql']}
),

tmp_state_bills_data_infos AS (
    SELECT /*+ PARALLEL(6) */
        b.num_rec,

        SUM(
            CASE
                WHEN NOT REGEXP_LIKE(i.co_concepto,
                    'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE')
                THEN i.csmo_fact ELSE 0
            END
        ) OVER (PARTITION BY b.num_rec) AS csmo_total_fact,

        SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_without_vat,

        SUM(CASE WHEN i.co_concepto LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_vat,

        SUM(CASE WHEN i.co_concepto IN ('CC119','CC118') THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_cont_without_vat,

        SUM(CASE WHEN i.co_concepto = 'CT841' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_vat,

        SUM(CASE WHEN i.co_concepto = 'CC101' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS meter_rent

    FROM tmp_state_bills_data b
    LEFT JOIN cmsadmin.imp_concepto i
        ON b.num_rec = i.num_rec
)

SELECT /*+ PARALLEL(6) */ DISTINCT
    b.region,
    b.nom_unicom,
    b.calendar_year,
    b.reading_cycle,
    b.regroup_id,
    b.regroup_name,
    b.nis_rad,
    b.cust_name,
    b.pk_cust_id,
    b.meter_no,
    b.number_wires,
    b.pot_max_admis,
    i.csmo_total_fact,
    b.num_rec,
    b.f_fact,
    b.f_prev_puesta,
    b.imp_tot_rec,
    b.due_amount,
    b.est_act,
    b.tip_contr,
    b.ind_conversion,
    b.cod_cta_pago,
    b.num_rec_anul,
    i.amount_without_vat,
    i.amount_vat,
    i.govern_cont_without_vat,
    i.govern_vat,
    i.meter_rent,
    b.id_statement,
    b.bill_is_estimate
FROM tmp_state_bills_data b
LEFT JOIN tmp_state_bills_data_infos i
    ON b.num_rec = i.num_rec
    ",
            'binds' => array_merge([
                'year' => $year,
                'cycle' => $cycle,
            ], $dateCondition['binds'])
        ];
    }



    /* MT */

     public static function LOADING_TB_FACTURES_ETAT_MT_READING () : string 
{
    return "
        INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_mt_readings t
        SELECT /*+ PARALLEL(6) */ DISTINCT
            t.nis_rad,
            ap.num_sum,
            t.num_rec,
            ap.id_reg,
            ap.tip_csmo,
            ap.lect,
            ap.csmo,
            ap.cte_apa,
            ap.f_lect,
            ap.f_actual,
            COUNT(DISTINCT ap.id_reg) OVER(PARTITION BY t.num_rec) nber_of_readings,
            ROW_NUMBER() OVER(PARTITION BY t.num_rec ORDER BY ap.f_lect DESC, ap.f_actual DESC, ap.id_reg DESC) readings_order,
            t.bill_is_estimate,
            1 reading_type
        FROM cmsreport.tb_factures_etat_mt_4_memoires t
        LEFT JOIN cmsadmin.rel_rec_csmo rc ON t.num_rec = rc.num_rec
        LEFT JOIN cmsadmin.apmedida_co ap ON rc.id_apco_ant = ap.id_reg

        UNION

        SELECT /*+ PARALLEL(6) */ DISTINCT
            t.nis_rad,
            ac.num_sum,
            t.num_rec,
            ac.id_reg,
            ac.tip_csmo,
            ac.lect,
            ac.csmo,
            ac.cte_apa,
            ac.f_lect,
            ac.f_actual,
            COUNT(DISTINCT ac.id_reg) OVER(PARTITION BY t.num_rec) nber_of_readings,
            ROW_NUMBER() OVER(PARTITION BY t.num_rec ORDER BY ac.f_lect DESC, ac.f_actual DESC, ac.id_reg DESC) readings_order,
            t.bill_is_estimate,
            2 reading_type
        FROM cmsreport.tb_factures_etat_mt_4_memoires t
        LEFT JOIN cmsadmin.rel_rec_csmo rc ON t.num_rec = rc.num_rec
        LEFT JOIN cmsadmin.apmedida_co ac ON rc.id_apco_act = ac.id_reg
    ";
}

    public static function LOADING_TB_FACTURES_ETAT_MT_INFOS () : string 
{
    return "
        INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_mt_infos t
        WITH tb_ai_kwhs AS (
            SELECT DISTINCT
                ai.bill_no,
                ai.tip_csmo,
                MAX(ai.lect) KEEP (DENSE_RANK LAST ORDER BY ai.f_lect, ai.f_actual, ai.id_reg)
                    OVER (PARTITION BY ai.bill_no, ai.tip_csmo) reading
            FROM cmsreport.tb_factures_etat_mt_readings ai
            WHERE ai.reading_type = 1
        ),
        tb_ni_kwhs AS (
            SELECT DISTINCT
                ni.bill_no,
                ni.tip_csmo,
                MAX(ni.cte_apa) KEEP (DENSE_RANK LAST ORDER BY ni.f_lect, ni.f_actual, ni.id_reg)
                    OVER (PARTITION BY ni.bill_no, ni.tip_csmo) coeff,
                MAX(ni.lect) KEEP (DENSE_RANK LAST ORDER BY ni.f_lect, ni.f_actual, ni.id_reg)
                    OVER (PARTITION BY ni.bill_no, ni.tip_csmo) reading,
                SUM(CASE WHEN ni.readings_order <> 1 THEN ni.csmo ELSE 0 END)
                    OVER (PARTITION BY ni.bill_no, ni.tip_csmo) reading_for_ai_if_estimate,
                SUM(ni.csmo)
                    OVER (PARTITION BY ni.bill_no, ni.tip_csmo) reading_if_estimate
            FROM cmsreport.tb_factures_etat_mt_readings ni
            WHERE ni.reading_type = 2
        )
        SELECT /*+ PARALLEL(4) */ DISTINCT
            b.nis_rad,
            b.num_rec,
            ni.tip_csmo,
            CASE WHEN b.bill_is_estimate = 0 
                 THEN ai.reading
                 ELSE NVL(ai.reading, 0) + NVL(ni.reading_for_ai_if_estimate, 0)
            END prev_actual_read,
            CASE WHEN b.bill_is_estimate = 0 
                 THEN ni.reading
                 ELSE NVL(ai.reading, 0) + NVL(ni.reading_if_estimate, 0)
            END hht_current_index,
            ni.coeff
        FROM cmsreport.tb_factures_etat_mt_4_memoires b
        LEFT JOIN tb_ai_kwhs ai ON b.num_rec = ai.bill_no
        LEFT JOIN tb_ni_kwhs ni ON b.num_rec = ni.bill_no
            AND ai.tip_csmo = ni.tip_csmo
        WHERE ni.tip_csmo IS NOT NULL
    ";
}


     public static function LOADING_TB_FACTURES_ETAT_MT_4_MEMOIRES_WITH_FACTURE(
        int $year,
        int $cycle,
        string $regroupName,
        string $dateDebut,
        string $dateFin
    ): array
{
    $dateCondition = self::createDateCondition($dateDebut, $dateFin);

    $sql = "
INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_mt_4_memoires t
(
    region,
    nom_unicom,
    calendar_year,
    reading_cycle,
    regroup_id,
    regroup_name,
    nis_rad,
    cust_name,
    pk_cust_id,
    meter_no,
    number_wires,
    pot_max_admis,
    csmo_total_fact,
    num_rec,
    f_fact,
    f_prev_puesta,
    imp_tot_rec,
    due_amount,
    est_act,
    tip_contr,
    ind_conversion,
    cod_cta_pago,
    num_rec_anul,
    amount_without_vat,
    amount_vat,
    govern_cont_without_vat,
    govern_vat,
    meter_rent,
    id_statement,
    bill_is_estimate
)
WITH tmp_state_bills_data AS (
    SELECT /*+ PARALLEL(8) */ DISTINCT
        s.nom_area AS region,
        s.nom_unicom,
        :year: AS calendar_year,
        :cycle: AS reading_cycle,
        t.tutelle AS regroup_id,
        t.tutelle AS regroup_name,
        t.contrat AS nis_rad,
        s.cust_name,
        s.pk_cust_id,
        s.meter_no,
        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
        s.pot_max_admis,
        r.num_rec,
        r.f_fact,
        r.f_prev_puesta,
        r.imp_tot_rec,
        (r.imp_tot_rec - r.imp_cta) AS due_amount,
        r.est_act,
        s.tip_contr,
        r.ind_conversion,
        r.cod_cta_pago,
        r.num_rec_anul,
        r.id_statement,
        DECODE(r.ind_real_est, 1, 0, 1) AS bill_is_estimate
    FROM cmsreport.tmp_ref_etat_with_facture t
    JOIN cmsadmin.recibos r ON t.contrat = r.num_rec
    JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
    WHERE r.tip_rec = 'TR010'
      {$dateCondition['sql']}
),
tmp_state_bills_data_infos AS (
    SELECT /*+ PARALLEL(6) */
        b.num_rec,

        SUM(
            CASE
                WHEN NOT REGEXP_LIKE(i.co_concepto,
                    'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE')
                THEN i.csmo_fact ELSE 0
            END
        ) OVER (PARTITION BY b.num_rec) AS csmo_total_fact,

        SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_without_vat,

        SUM(CASE WHEN i.co_concepto LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_vat,

        SUM(CASE WHEN i.co_concepto IN ('CC119','CC118') THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_cont_without_vat,

        SUM(CASE WHEN i.co_concepto = 'CT841' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_vat,

        SUM(CASE WHEN i.co_concepto = 'CC101' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS meter_rent

    FROM tmp_state_bills_data b
    LEFT JOIN cmsadmin.imp_concepto i
        ON b.num_rec = i.num_rec
)

SELECT /*+ PARALLEL(6) */ DISTINCT
    b.region,
    b.nom_unicom,
    b.calendar_year,
    b.reading_cycle,
    b.regroup_id,
    b.regroup_name,
    b.nis_rad,
    b.cust_name,
    b.pk_cust_id,
    b.meter_no,
    b.number_wires,
    b.pot_max_admis,
    i.csmo_total_fact,
    b.num_rec,
    b.f_fact,
    b.f_prev_puesta,
    b.imp_tot_rec,
    b.due_amount,
    b.est_act,
    b.tip_contr,
    b.ind_conversion,
    b.cod_cta_pago,
    b.num_rec_anul,
    i.amount_without_vat,
    i.amount_vat,
    i.govern_cont_without_vat,
    i.govern_vat,
    i.meter_rent,
    b.id_statement,
    b.bill_is_estimate
FROM tmp_state_bills_data b
LEFT JOIN tmp_state_bills_data_infos i
    ON b.num_rec = i.num_rec
";

    return [
        'sql'   => $sql,
        'binds' => [
            'year'  => $year,
            'cycle' => $cycle,
        ] + $dateCondition['binds'],
    ];
}

     public static function LOADING_TB_FACTURES_ETAT_MT_4_MEMOIRES_WITH_CONTRAT(
        int $year,
        int $cycle,
        string $regroupName,
        string $dateDebut,
        string $dateFin
    ): array
{
    $dateCondition = self::createDateCondition($dateDebut, $dateFin);

    $sql = "
INSERT /*+ APPEND PARALLEL(t) */ INTO cmsreport.tb_factures_etat_mt_4_memoires t
(
    region,
    nom_unicom,
    calendar_year,
    reading_cycle,
    regroup_id,
    regroup_name,
    nis_rad,
    cust_name,
    pk_cust_id,
    meter_no,
    number_wires,
    pot_max_admis,
    csmo_total_fact,
    num_rec,
    f_fact,
    f_prev_puesta,
    imp_tot_rec,
    due_amount,
    est_act,
    tip_contr,
    ind_conversion,
    cod_cta_pago,
    num_rec_anul,
    amount_without_vat,
    amount_vat,
    govern_cont_without_vat,
    govern_vat,
    meter_rent,
    id_statement,
    bill_is_estimate
)
WITH tmp_state_bills_data AS (
    SELECT /*+ PARALLEL(8) */ DISTINCT
        s.nom_area AS region,
        s.nom_unicom,
        :year: AS calendar_year,
        :cycle: AS reading_cycle,
        t.tutelle AS regroup_id,
        t.tutelle AS regroup_name,
        t.contrat AS nis_rad,
        s.cust_name,
        s.pk_cust_id,
        s.meter_no,
        DECODE(s.tip_fase,'FA001', 2, 4) AS number_wires,
        s.pot_max_admis,
        r.num_rec,
        r.f_fact,
        r.f_prev_puesta,
        r.imp_tot_rec,
        (r.imp_tot_rec - r.imp_cta) AS due_amount,
        r.est_act,
        s.tip_contr,
        r.ind_conversion,
        r.cod_cta_pago,
        r.num_rec_anul,
        r.id_statement,
        DECODE(r.ind_real_est, 1, 0, 1) AS bill_is_estimate
    FROM cmsreport.tmp_ref_etat_with_contrat t
    JOIN cmsadmin.recibos r ON t.contrat = r.nis_rad
    JOIN cmsreport.tb_customers_infos s ON t.contrat = s.nis_rad
    WHERE r.tip_rec = 'TR010'
      {$dateCondition['sql']}
),
tmp_state_bills_data_infos AS (
    SELECT /*+ PARALLEL(6) */
        b.num_rec,

        SUM(
            CASE
                WHEN NOT REGEXP_LIKE(i.co_concepto,
                    'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE')
                THEN i.csmo_fact ELSE 0
            END
        ) OVER (PARTITION BY b.num_rec) AS csmo_total_fact,

        SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_without_vat,

        SUM(CASE WHEN i.co_concepto LIKE 'CT%' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS amount_vat,

        SUM(CASE WHEN i.co_concepto IN ('CC119','CC118') THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_cont_without_vat,

        SUM(CASE WHEN i.co_concepto = 'CT841' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS govern_vat,

        SUM(CASE WHEN i.co_concepto = 'CC101' THEN i.imp_concepto ELSE 0 END)
            OVER (PARTITION BY b.num_rec) AS meter_rent

    FROM tmp_state_bills_data b
    LEFT JOIN cmsadmin.imp_concepto i
        ON b.num_rec = i.num_rec
)

SELECT /*+ PARALLEL(6) */ DISTINCT
    b.region,
    b.nom_unicom,
    b.calendar_year,
    b.reading_cycle,
    b.regroup_id,
    b.regroup_name,
    b.nis_rad,
    b.cust_name,
    b.pk_cust_id,
    b.meter_no,
    b.number_wires,
    b.pot_max_admis,
    i.csmo_total_fact,
    b.num_rec,
    b.f_fact,
    b.f_prev_puesta,
    b.imp_tot_rec,
    b.due_amount,
    b.est_act,
    b.tip_contr,
    b.ind_conversion,
    b.cod_cta_pago,
    b.num_rec_anul,
    i.amount_without_vat,
    i.amount_vat,
    i.govern_cont_without_vat,
    i.govern_vat,
    i.meter_rent,
    b.id_statement,
    b.bill_is_estimate
FROM tmp_state_bills_data b
LEFT JOIN tmp_state_bills_data_infos i
    ON b.num_rec = i.num_rec
";

    return [
        'sql'   => $sql,
        'binds' => [
            'year'  => $year,
            'cycle' => $cycle,
        ] + $dateCondition['binds'],
    ];
}

    public static function GENERATE_DATA_PRINT_MT(): string 
{
    return "
        SELECT 
            'F' || identifiant_fichier_pdf AS identifiant_fichier_pdf,
            '\\\\10.250.90.33\\shared folders\\Facture_CMS\\MT\\' 
                || f_batch_date 
                || '\\bills_metered_' 
                || nom_sucursal 
                || '__F' || identifiant_fichier_pdf 
                || '_' || f_batch_date 
                || '_0.pdf' AS nom_fichier,

            LISTAGG(numero_de_page, ';') 
                WITHIN GROUP (ORDER BY numero_de_page)
                OVER (PARTITION BY identifiant_fichier_pdf) AS liste_numero_de_page,

            LISTAGG(numero_de_page + 1, ';') 
                WITHIN GROUP (ORDER BY numero_de_page)
                OVER (PARTITION BY identifiant_fichier_pdf) AS liste_numero_de_page_duplicata,

            LISTAGG(numero_de_page + 2, ';') 
                WITHIN GROUP (ORDER BY numero_de_page)
                OVER (PARTITION BY identifiant_fichier_pdf) AS liste_numero_de_page_archive,

            LISTAGG(numero_de_page + 3, ';') 
                WITHIN GROUP (ORDER BY numero_de_page)
                OVER (PARTITION BY identifiant_fichier_pdf) AS liste_numero_de_page_decharge

        FROM (
            SELECT /*+ PARALLEL(10) */
                b.file_id AS identifiant_fichier_pdf,
                REPLACE(TRIM(su.nom_sucursal), ' ', '_') AS nom_sucursal,
                TO_CHAR(f_batch_date,'YYYYMMDD') AS f_batch_date,
                t.num_rec AS num_rec,
                ((b.extract_seq - 1) * 4 + 1) AS numero_de_page
            FROM cmsreport.tb_factures_etat_mt_4_memoires t
            JOIN cmsadmin.bill_extraction_list b 
                ON t.num_rec = b.num_rec
            LEFT JOIN cmsadmin.sucursales su 
                ON su.cod_agencia = 100 
               AND su.cod_sucursal = b.cod_unicom
        )
    ";
}

     public static function GENERATE_DATA_MEMOIRE_MT () : string 
    {
        return "
            select /*+  parallel(8) */ distinct
            r.region,
            r.nom_unicom agency,
            r.calendar_year,
            r.reading_cycle,
            r.regroup_id regroup_code,
            r.regroup_name regroup_name,
            r.nis_rad service_no,
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
            trunc (r.imp_tot_rec) due_amount,
            estado(r.est_act) bill_status,
            substr (tipo(r.tip_contr), 1, 2) cat_cli,

            r.amount_without_vat,
            r.amount_vat,
            r.govern_cont_without_vat,
            r.govern_vat,
            r.meter_rent,

            max(case when i.tip_csmo = 'CO003' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_active_hors_pointe_imp,
            max(case when i.tip_csmo = 'CO003' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_active_hors_pointe_imp,
            max(case when i.tip_csmo = 'CO003' then i.coeff else 1 end) over(partition by r.num_rec) coeff_active_hors_pointe_imp,

            max(case when i.tip_csmo = 'CO008' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_active_hors_pointe_exp,
            max(case when i.tip_csmo = 'CO008' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_active_hors_pointe_exp,
            max(case when i.tip_csmo = 'CO008' then i.coeff else 1 end) over(partition by r.num_rec) coeff_active_hors_pointe_exp,

            max(case when i.tip_csmo = 'CO002' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_active_pointe_imp,
            max(case when i.tip_csmo = 'CO002' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_active_pointe_imp,
            max(case when i.tip_csmo = 'CO002' then i.coeff else 1 end) over(partition by r.num_rec) coeff_active_pointe_imp,

            max(case when i.tip_csmo = 'CO007' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_active_pointe_exp,
            max(case when i.tip_csmo = 'CO007' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_active_pointe_exp,
            max(case when i.tip_csmo = 'CO007' then i.coeff else 1 end) over(partition by r.num_rec) coeff_active_pointe_exp,

            max(case when i.tip_csmo = 'CO005' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_reactive_hors_pointe_imp,
            max(case when i.tip_csmo = 'CO005' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_reactive_hors_pointe_imp,
            max(case when i.tip_csmo = 'CO005' then i.coeff else 1 end) over(partition by r.num_rec) coeff_reactive_hors_pointe_imp,

            max(case when i.tip_csmo = 'CO025' then i.prev_actual_read else '' end) over(partition by r.num_rec) prev_reactive_pointe_imp,
            max(case when i.tip_csmo = 'CO025' then i.hht_current_index else '' end) over(partition by r.num_rec) curr_reactive_pointe_imp,
            max(case when i.tip_csmo = 'CO025' then i.coeff else 1 end) over(partition by r.num_rec) coeff_reactive_pointe_imp

        from cmsreport.tb_factures_etat_mt_4_memoires r
            left join cmsreport.tb_factures_etat_mt_infos i on r.num_rec = i.num_rec
        where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
            and r.cod_cta_pago not in (2000001, 2000000)
        order by 1, 2, 7
            ";
            }
}
