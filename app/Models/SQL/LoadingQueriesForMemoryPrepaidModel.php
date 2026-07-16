<?php

namespace App\Models\SQL;

class LoadingQueriesForMemoryPrepaidModel
{
    /* ================= PREPAID ================= */

    public static function LOADING_TMP_DETAILS_4_MEMOIRES_PREPAID_WITH_CONTRAT_ONLY(
    int $year,
    int $cycle,
    string $regroupName,
    string $dateDebut,
    string $dateFin
) : array
{
    // Sécurisation minimale du nom du regroupement
    $regroupName = str_replace("'", "''", $regroupName);

    // ⚡ Conversion des dates d'entrée au format AAAA-MM-JJ HH24:MI:SS
    $dtDebut = \DateTime::createFromFormat('d/m/Y', $dateDebut);
    $dtFin   = \DateTime::createFromFormat('d/m/Y', $dateFin);

    $dateDebutOracle = "TO_DATE('" . $dtDebut->format('Y-m-d') . " 00:00:00','YYYY-MM-DD HH24:MI:SS')";
    $dateFinOracle   = "TO_DATE('" . $dtFin->format('Y-m-d')   . " 00:00:00','YYYY-MM-DD HH24:MI:SS')";

    return [
        'sql' => "
            INSERT INTO cmsreport.tmp_details_4_memoires_prepaid (
                calendar_year,
                reading_cycle,
                token,
                energy,
                company_charge_amount,
                charge_amount,
                recovered_debt,
                remain_debt,
                czyid,
                xm,
                bz,
                regroup_code_or_name,
                meter_no,
                mttc2,
                real_mht,
                real_tax,
                real_mttc,
                e_amount,
                company_e_amount,
                tva2,
                tenderamt,
                company_tenderamt,
                optime,
                no_recu,
                cduname,
                hh,
                customer_name
            )

            WITH base_data AS (
                SELECT /*+ DRIVING_SITE(vom) */
                    vom.ordersid,
                    vom.meterno,
                    vom.cduname,
                    -- ⚡ Conversion correcte de optime en DATE
                    TO_DATE(vom.optime, 'YYYY-MM-DD HH24:MI:SS') AS optime,
                    vom.vatamount,

                    om.energy,
                    om.energy_amount,
                    om.company_energy_amount,
                    om.charge_amount,
                    om.company_charge_amount,
                    om.tenderamt,
                    om.company_tenderamt,

                    NVL(od.amount, 0) AS recovered_debt,
                    NVL(od.remain_debt, 0) AS remain_debt,

                    ot.token,
                    ot.ordersid AS no_recu,

                    y.hh,
                    y.bz,
                    y.hm AS customer_name,

                    c.czyid,
                    c.xm

                FROM prepaid.vw_order_master_for_billing@powernet_db_link vom

                JOIN prepaid.da_yh@powernet_db_link y
                    ON y.hh = vom.accountno

                LEFT JOIN prepaid.order_master@powernet_db_link om
                    ON om.ordersid = vom.ordersid

                LEFT JOIN prepaid.order_token@powernet_db_link ot
                    ON ot.ordersid = vom.ordersid

                LEFT JOIN prepaid.order_debt@powernet_db_link od
                    ON od.ordersid = vom.ordersid
                   AND od.remain_debt = 0

                LEFT JOIN prepaid.qx_czy@powernet_db_link c
                    ON c.czyid = om.operator

                -- ✅ Filtrage correct avec constantes au format compatible
                WHERE TO_DATE(vom.optime, 'YYYY-MM-DD HH24:MI:SS') >= $dateDebutOracle
                  AND TO_DATE(vom.optime, 'YYYY-MM-DD HH24:MI:SS') <  $dateFinOracle
                  AND EXISTS (
                        SELECT 1
                        FROM cmsreport.tmp_ref_confacresu r
                        WHERE r.confacresu = y.hh
                  )
            )

            SELECT
                {$year} AS calendar_year,
                {$cycle} AS reading_cycle,

                CASE
                    WHEN LENGTH(token) = 20 THEN
                        SUBSTR(token,1,4)||'-'||SUBSTR(token,5,4)||'-'||SUBSTR(token,9,4)||'-'||SUBSTR(token,13,4)||'-'||SUBSTR(token,17,4)
                    ELSE '0'
                END AS token,

                NVL(energy,0),
                NVL(company_charge_amount,0),
                NVL(charge_amount,0),
                NVL(recovered_debt,0),
                NVL(remain_debt,0),

                czyid,
                xm,
                bz,

                '{$regroupName}' AS regroup_code_or_name,

                meterno,

                NVL(tenderamt,0)+NVL(company_tenderamt,0) AS mttc2,

                NVL(company_energy_amount,0)
                    + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                        THEN NVL(energy_amount,0) ELSE 0 END AS real_mht,

                NVL(company_charge_amount,0)
                    + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                        THEN NVL(charge_amount,0) ELSE 0 END AS real_tax,

                NVL(company_energy_amount,0)
                + NVL(company_charge_amount,0)
                + NVL(recovered_debt,0)
                + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                    THEN NVL(energy_amount,0)+NVL(charge_amount,0) ELSE 0 END AS real_mttc,

                NVL(energy_amount,0) AS e_amount,
                NVL(company_energy_amount,0) AS company_e_amount,
                NVL(vatamount,0) AS tva2,
                NVL(tenderamt,0),
                NVL(company_tenderamt,0),

                -- ⚡ TO_CHAR pour conserver le format affiché
                TO_CHAR(optime, 'DD/MM/YYYY HH24:MI:SS') AS transaction_date,
                NVL(no_recu,'0'),
                NVL(cduname,'Batch_Contingent'),
                hh,
                NVL(customer_name,'0')

            FROM base_data
            where company_charge_amount > 0 or NVL(cduname, '') LIKE '%{$regroupName}%'
        ",
        'binds' => []
    ];
}

    public static function LOADING_TMP_DETAILS_4_MEMOIRES_PREPAID_WITH_RECU_ONLY(
    int $year,
    int $cycle,
    string $regroupName,
    ?string $dateDebut,
    ?string $dateFin
): array
{
    $regroupName = str_replace("'", "''", $regroupName);

    $dateCondition = "";

    if (!empty($dateDebut)) {
        $dateCondition .= " AND vom.optime >= DATE '{$dateDebut}' ";
    }

    if (!empty($dateFin)) {
        $dateCondition .= " AND vom.optime < DATE '{$dateFin}' + 1 ";
    }

    return [
        'sql' => "
            INSERT /*+ APPEND PARALLEL(4) */
            INTO cmsreport.tmp_details_4_memoires_prepaid (
                calendar_year,
                reading_cycle,
                token,
                energy,
                company_charge_amount,
                charge_amount,
                recovered_debt,
                remain_debt,
                czyid,
                xm,
                bz,
                regroup_code_or_name,
                meter_no,
                mttc2,
                real_mht,
                real_tax,
                real_mttc,
                e_amount,
                company_e_amount,
                tva2,
                tenderamt,
                company_tenderamt,
                optime,
                no_recu,
                cduname,
                hh,
                customer_name
            )

            WITH base_data AS (
                SELECT /*+ DRIVING_SITE(vom) */
                    vom.ordersid,
                    vom.meterno,
                    vom.cduname,
                    TO_DATE(vom.optime, 'YYYY-MM-DD HH24:MI:SS') AS optime,
                    vom.vatamount,

                    om.energy,
                    om.energy_amount,
                    om.company_energy_amount,
                    om.charge_amount,
                    om.company_charge_amount,
                    om.tenderamt,
                    om.company_tenderamt,

                    NVL(od.amount,0)      recovered_debt,
                    NVL(od.remain_debt,0) remain_debt,

                    ot.token,
                    ot.ordersid no_recu,

                    y.hh,
                    y.bz,
                    y.hm customer_name,

                    c.czyid,
                    c.xm
                FROM prepaid.vw_order_master_for_billing@powernet_db_link vom
                JOIN prepaid.da_yh@powernet_db_link y
                    ON y.hh = vom.accountno
                LEFT JOIN prepaid.order_master@powernet_db_link om
                    ON om.ordersid = vom.ordersid
                LEFT JOIN prepaid.order_token@powernet_db_link ot
                    ON ot.ordersid = vom.ordersid
                LEFT JOIN prepaid.qx_czy@powernet_db_link c
                    ON c.czyid = om.operator
                LEFT JOIN prepaid.order_debt@powernet_db_link od
                    ON od.ordersid = vom.ordersid
                   AND od.remain_debt = 0
                WHERE EXISTS (
                    SELECT 1
                    FROM cmsreport.tmp_ref_confacresu r
                    WHERE r.confacresu = TO_CHAR(vom.ordersid)
                )
                {$dateCondition}
            )

            SELECT
                {$year},
                {$cycle},

                CASE
                    WHEN LENGTH(token) = 20 THEN
                        SUBSTR(token,1,4)||'-'||SUBSTR(token,5,4)||'-'||
                        SUBSTR(token,9,4)||'-'||SUBSTR(token,13,4)||'-'||
                        SUBSTR(token,17,4)
                    ELSE NULL
                END,

                NVL(energy,0),
                NVL(company_charge_amount,0),
                NVL(charge_amount,0),
                NVL(recovered_debt,0),
                NVL(remain_debt,0),

                czyid,
                xm,
                bz,

                '{$regroupName}',

                meterno,

                NVL(tenderamt,0) + NVL(company_tenderamt,0),

                NVL(company_energy_amount,0)
                    + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                        THEN NVL(energy_amount,0) ELSE 0 END,

                NVL(company_charge_amount,0)
                    + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                        THEN NVL(charge_amount,0) ELSE 0 END,

                NVL(company_energy_amount,0)
                + NVL(company_charge_amount,0)
                + NVL(recovered_debt,0)
                + CASE WHEN cduname IN ('PREPAID TEAM','SONATREL STAFF PREPAID')
                    THEN NVL(energy_amount,0) + NVL(charge_amount,0) ELSE 0 END,

                NVL(energy_amount,0),
                NVL(company_energy_amount,0),
                NVL(vatamount,0),
                NVL(tenderamt,0),
                NVL(company_tenderamt,0),

                optime,
                no_recu,

                NVL(cduname,'Batch_Contingent'),
                hh,
                NVL(customer_name,'0')

            FROM base_data
            where company_charge_amount > 0 or NVL(cduname, '') LIKE '%{$regroupName}%'
        ",
        'binds' => []
    ];
}


    public static function GENERATE_DATA_MEMOIRES_FOR_PREPAID(): string
    {
        return "
            SELECT /*+ parallel(8) */ DISTINCT
                a.nom_area AS region,
                a.nom_zona AS division,
                a.nom_unicom AS agency,
                nr.hh AS contrat,
                nr.customer_name,
                CASE 
                    WHEN nr.bz = '0' THEN '0'
                    ELSE REGEXP_REPLACE(nr.bz, '(^SO0?|_SO$)', '')
                END AS matricule, -- matricule de l'agent qui recoit son contingent
                nr.energy AS contingent,
                nr.token,
                nr.meter_no,
                d.BXH AS Modele_compteur,
                CASE d.BXH 
                    WHEN 'HXP100DI' THEN '2 fils'
                    WHEN 'HXE310-P' THEN '4 fils'
                    WHEN 'HXE330' THEN '4 fils'
                    ELSE 'NULL'
                END AS Nombre_de_Fils,
                nr.E_amount, -- montant de la recharge : nombre kwh * prix unitaire
                nr.company_E_amount,
                nr.company_charge_amount,
                nr.charge_amount,
                nr.recovered_debt,
                nr.REAL_MHT AS Amount_Without_Tax,
                nr.REAL_TAX AS TVA,
                nr.REAL_MTTC AS Amount_With_Tax,
                nr.optime date_transaction,
                nr.no_recu,
                nr.cduname AS Libelle_Caisse
            FROM CMSREPORT.TMP_DETAILS_4_MEMOIRES_PREPAID nr
            LEFT JOIN cmsreport.tb_customers_infos a ON a.meter_no = nr.meter_no
            LEFT JOIN prepaid.da_bj@powernet_db_link d ON nr.meter_no = d.bjjh
        ";
    }

    public static function GENERATE_MEMOIRES_FOR_PREPAID(): string
    {
        return "
            SELECT /*+ parallel(8) */ DISTINCT
                a.nom_area AS region,
                a.nom_zona AS division,
                a.nom_unicom AS agency,
                nr.hh AS service_no,
                nr.customer_name,
                CASE 
                    WHEN nr.bz = '0' THEN '0'
                    ELSE REGEXP_REPLACE(nr.bz, '(^SO0?|_SO$)', '')
                END registration_number,
                nr.no_recu receipt_no,
                nr.meter_no,
                nr.energy AS contingent,
                nr.token,
                TO_CHAR(nr.optime, 'DD/MM/YYYY HH24:MI:SS') AS transaction_date,
                nr.real_mht AS amount_without_vat,
                nr.real_tax AS amount_vat,
                nr.real_mttc AS amount_with_tax,               
                nr.calendar_year,
                nr.reading_cycle
            FROM CMSREPORT.TMP_DETAILS_4_MEMOIRES_PREPAID nr            
            LEFT JOIN cmsreport.tb_customers_infos a ON a.meter_no = nr.meter_no
            LEFT JOIN prepaid.da_bj@powernet_db_link d ON nr.meter_no = d.bjjh
        ";
    }
}
