<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class MemoirePostpaidExcel extends BaseConfig
{
    /**
     * Infos société (bloc gauche A6:C12)
     */
    public array $companyInfo = [
        ['value' => 'Société anonyme au capital de 43 903 690 000 F. CFA.', 'fontSize' => 10, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['value' => 'Siège social: Avenue de Gaulle - Douala', 'fontSize' => 10, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['value' => 'Registre de Commerce N 4625 - N Statistique: 211511001 - S', 'fontSize' => 10, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['value' => 'B.P 4077 Douala - Tél: 33 42 54 44 - Fax: 33 42 22 47', 'fontSize' => 10, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['value' => 'N° Contribuable : M05700001633D', 'fontSize' => 10, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['value' => 'Site Internet : https://my.eneo.cm', 'fontSize' => 9,  'fontColor' => '000000', 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
    ];

    /**
     * Entêtes du tableau (ligne 12)
     */
    public array $headers = [
        'Noms du Client / Customer Name',
        'Agence / Agency',
        'Réf. Client CMS / CMS Customer ID',
        'Date Facture / Billing Date',
        'No Facture / Bill ID No',
        'No Compteur / Meter No',
        'Ancien INDEX / Previous Reading',
        'Nouvel INDEX / Present Reading',
        'Consommation / Consumption(Kwh)',
        'Coefficient / Ratio',
        'Location Compteur / Meter Rent',
        'Montant Total (HT) / Total Amount (Without Tax)',
        'Taxes Totales / Total Tax',
        'Montant Total (TTC) / Total Amount With Tax',
        'Montant à Payer / Due Amount',
    ];

    /**
     * Entêtes additionnels (zone haute)
     */
    public array $headersAdditionnal = [
        ['cell' => 'D2', 'merge' => 'D2:J2', 'value' => 'FACTURE D\'ELECTRICITE / ELECTRICITY BILL', 'fontSize' => 18, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_CENTER, 'border' => true,],
        ['cell' => 'F3', 'merge' => 'F3:H3', 'value' => 'Mémoire / Memory', 'fontSize' => 10, 'bold' => true, 'italic' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'D6', 'merge' => 'D6:F6', 'value' => 'Région / Region :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'D7', 'merge' => 'D7:F7', 'value' => 'Code Regroupement / Regroup ID :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'D8', 'merge' => 'D8:F8', 'value' => 'Nom Regroupement / Regroup Name :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'K6', 'value' => 'Mois Facturation :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'K8', 'value' => 'Date édition mémoire :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'N6', 'merge' => 'N6:O6', 'value' => 'N° MÉMOIRE / MEMORY No', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT, 'border' => true,],
    ];

    /**
     * Pied de page (bloc bas)
     */
    public array $footers = [
        ['value' => 'Bon à savoir / Good to know'],
        ['value' => 'Ce mémoire comprend l’ensemble des factures individuelles émises ce mois et dont les installations sont rattachées à votre code regroupement. SVP bien vouloir signaler toute anomalie à votre gestionnaire de compte'],
        ['value' => 'This memory contains the summary of individual bills generated this month that are attached to your regrouping code. Please, do inform your account manager of any identified anomalies'],
        ['value' => 'ENERGIZING CAMEROON'],
        ['value' => 'FACTURE UNIQUE - NE PEUT SERVIR D\'ACQUIT / SINGLE COPY BILL - SHALL NOT SERVE AS RECEIPT'],
        ['value' => 'Signature'],
    ];
}