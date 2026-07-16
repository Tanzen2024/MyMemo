<?php

namespace App\Models\SQL;

class LoadingQueriesForMemoryRegionsModel
{

/* ================= RÉGIONS ================= */


    public static function LOADING_TB_FACTURES_MEMOIRES_REGIONS (int $year, int $cycle, string $regroup, string $debut, string $fin) : array
    {
        return [
            'sql' => "
                insert /*+ append parallel(t) */ into cmsreport.tb_factures_memoires_regions_2 t (
                    SELECT /*+ PARALLEL(8) */ DISTINCT
                    s.nom_area AS region,
                    s.nom_unicom,
                    :year: AS calendar_year,
                    :cycle: AS reading_cycle,
                    s.regroup_id,
                    s.regroup_name,
                    r.nis_rad,
                    s.cust_name,
                    s.pk_cust_id,
                    s.meter_no,
                    DECODE(s.tip_fase, 'FA001', 2, 4) AS number_wires,
                    s.pot_max_admis,
                    SUM(CASE WHEN NOT REGEXP_LIKE(i.co_concepto, 'FIXED PREMIUM CHARGE|COMPENSATION|OVERLOAD CHARGE') THEN i.csmo_fact ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) AS csmo_total_fact,
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
                    SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN i.imp_concepto ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) AS amount_without_vat,
                    TRUNC((SUM(CASE WHEN i.co_concepto NOT LIKE 'CT%' THEN -i.imp_concepto ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) + r.imp_tot_rec)) AS amount_vat,
                    SUM(CASE WHEN i.co_concepto IN ('CC119', 'CC118') THEN i.imp_concepto ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) AS govern_cont_without_vat,
                    SUM(CASE WHEN i.co_concepto = 'CT841' THEN i.imp_concepto ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) AS govern_vat,
                    SUM(CASE WHEN i.co_concepto = 'CC101' THEN i.imp_concepto ELSE 0 END) OVER(PARTITION BY r.num_rec, s.nom_area) AS meter_rent
                FROM (
                    SELECT /*+ PARALLEL(4) */ DISTINCT s.nis_rad
                    FROM cmsadmin.sumcon s
                    JOIN cmsadmin.cliente_identificador x ON s.cod_cli = x.cod_cli AND x.tip_doc IN ('TD012', 'TD013')
                    MINUS
                    SELECT /*+ PARALLEL(2) */ contrat AS nis_rad FROM cmsreport.tmp_referentiel_general
                ) t
                JOIN cmsadmin.recibos r ON t.nis_rad = r.nis_rad
                JOIN cmsreport.tb_customers_infos s ON r.nis_rad = s.nis_rad
                LEFT JOIN cmsadmin.imp_concepto i ON r.num_rec = i.num_rec
                WHERE r.tip_rec = 'TR010'
                    AND r.f_prev_puesta BETWEEN TO_DATE(:debut:, 'YYYY-MM-DD') AND TO_DATE(:fin:, 'YYYY-MM-DD')
                )
            ",
            'binds' => [
                'year' => $year,
                'cycle' => $cycle,
                'debut' => $debut,
                'fin' => $fin,
            ]
        ];
    }
    


    /* ================= FACTURES INFOS ================= */

    public static function LOADING_TB_FACTURES_REGIONS_INFOS(int $year, int $cycle, string $regroup, string $debut, string $fin): array
    {
       return [
            'sql' => "
                insert /*+ append parallel(t) */ into cmsreport.tb_factures_regions_infos_new t (
                    SELECT /*+ PARALLEL(8) */ 
                        r.nis_rad,
                        r.num_rec,
                        NVL(MAX(ap.lect) KEEP (DENSE_RANK LAST ORDER BY ap.f_lect, ap.f_actual) OVER (PARTITION BY r.num_rec), 0) AS prev_actual_read,
                        NVL(MAX(ac.lect) KEEP (DENSE_RANK LAST ORDER BY ac.f_lect, ac.f_actual) OVER (PARTITION BY r.num_rec), 0) AS hht_current_index,
                        NVL(MAX(ac.cte_apa) KEEP (DENSE_RANK LAST ORDER BY ac.f_lect, ac.f_actual) OVER (PARTITION BY r.num_rec), 1) AS coeff
                    FROM cmsadmin.recibos r
                    JOIN (
                        SELECT /*+ PARALLEL(4) */ DISTINCT
                            s.nis_rad
                        FROM cmsadmin.sumcon s
                        JOIN cmsadmin.cliente_identificador x ON s.cod_cli = x.cod_cli AND x.tip_doc IN ('TD012', 'TD013')
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM cmsreport.tmp_referentiel_general tg
                            WHERE tg.contrat = s.nis_rad
                        )
                    ) t ON t.nis_rad = r.nis_rad
                    LEFT JOIN cmsadmin.rel_rec_csmo rc ON r.num_rec = rc.num_rec
                    LEFT JOIN cmsadmin.apmedida_co ap ON rc.id_apco_ant = ap.id_reg
                    LEFT JOIN cmsadmin.apmedida_co ac ON rc.id_apco_act = ac.id_reg
                    WHERE r.tip_rec = 'TR010'
                        AND r.f_prev_puesta BETWEEN TO_DATE(:debut:, 'YYYY-MM-DD') AND TO_DATE(:fin:, 'YYYY-MM-DD')
                    )
            ",
            'binds' => [
                'year' => $year,
                'cycle' => $cycle,
                'debut' => $debut,
                'fin' => $fin,
            ]
        ];
    }


    public static function REGIONS () : string
    {
        return "
            select /*+  parallel(8) */ distinct
                r.region,
                r.nom_unicom agency,
                r.calendar_year,
                r.reading_cycle,
                r.regroup_id regroup_code,
                r.regroup_name,
                r.nis_rad service_no,
                r.cust_name customer_name,
                r.pk_cust_id old_account_no,
                r.meter_no,
                r.number_wires,
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
                c.coeff,
                'LOT_1' type_lot
            from cmsreport.tb_factures_memoires_regions_2 r
                join cmsreport.tb_factures_memoires_regions a on r.nis_rad = a.nis_rad and r.num_rec_anul = a.num_rec_anul and a.est_act != 'ER018' and a.cod_cta_pago not in (2000000,2000001)
                left join cmsreport.tb_factures_regions_infos c on r.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago in (2000001, 2000000)
                and r.ind_conversion <> 1

            union

            select /*+  parallel(8) */ distinct
                r.region,
                r.nom_unicom agency,
                r.calendar_year,
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
                'LOT_2' type_lot
            from cmsreport.tb_factures_memoires_regions r
                left join cmsreport.tb_factures_regions_infos_new c on r.num_rec = c.num_rec
            where r.est_act not in ('ER010', 'ER015', 'ER018', 'ER915', 'ER600')
                and r.cod_cta_pago not in (2000001, 2000000)
                and r.ind_conversion <> 1
            order by 1, 2, 7
        ";
    }

     public static function generateMemoireRegions(): string
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
                r.regroup_name              
            FROM cmsreport.tb_factures_memoires_regions_2 r
            LEFT JOIN cmsreport.tb_factures_regions_infos_new c ON r.num_rec = c.num_rec
        ";
    }
}