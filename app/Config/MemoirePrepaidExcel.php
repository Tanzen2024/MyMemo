<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class MemoirePrepaidExcel extends BaseConfig
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
        'Région / Region',
        'Division / Division',
        'Agence / Agency',
        'Réf. Client CMS / CMS Customer ID',
        'Nom Client / Customer Name',
        'Matricule / Registration Number',
        'No Reçu / Receipt No',
        'No Compteur / Meter No',
        'Contingent / Energy',
        'Token / Token',
        'Date Transaction / Transaction Date',
        'Montant Total (HT) / Total Amount (Without Tax)',
        'Taxes Totales / Total Tax',
        'Montant Total (TTC) / Total Amount With Tax',
    ];

    /**
     * Entêtes additionnels (zone haute)
     */
    public array $headersAdditionnal = [
        ['cell' => 'E2', 'merge' => 'E2:I2', 'value' => 'FACTURE D\'ELECTRICITE / ELECTRICITY BILL', 'fontSize' => 18, 'fontColor' => '014BA0', 'bold' => true, 'align' => Alignment::HORIZONTAL_CENTER, 'border' => true],
        ['cell' => 'G3', 'merge' => 'F3:H3', 'value' => 'Mémoire / Memory', 'fontSize' => 10, 'bold' => true, 'italic' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'E6',  'value' => 'Client / Customer :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'E7',  'value' => 'NIU / NUI :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'E8',  'value' => 'RCCM / RCCM :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'J6', 'value' => 'Mois Facturation :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'J8', 'value' => 'Date édition mémoire :', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT],
        ['cell' => 'M6', 'merge' => 'M6:N6', 'value' => 'N° MÉMOIRE / MEMORY No', 'fontSize' => 10, 'bold' => true, 'align' => Alignment::HORIZONTAL_LEFT, 'border' => true,],
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