<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ExcelConfig extends BaseConfig
{
    // En-têtes
    public array $headers = [
        'Societe' => 'Société anonyme au capital de 43 903 690 000 F. CFA.',		
        'Siege' => 'Siège social: Avenue de Gaulle - Douala',		
        'Registre' => 'Registre de Commerce N 4625 - N Statistique: 211511001 - S',		
        'Adresse' => 'B.P 4077 Douala - Tél: 33 42 54 44 - Fax: 33 42 22 47',		
        'Contribuable' => 'N° Contribuable : M05700001633D',		
        'SiteWeb' => 'Site Internet : www.aessoneltoday.com',
        'Region' => 'Région / Region :',		
        'RegroupCode' => 'Code Regroupement / Regroup ID:',		
        'RegroupeName' => 'Nom Regroupement / Regroup Name :',
        'GrandTitre' => 'FACTURE D\'ELECTRICITE / ELECTRICITY BILL',							
        'Memoire' => 'Mémoire / Memory',
        'Cycle' => 'Mois Facturation :',	
        'CurrentDate' => 'Date édition mémoire :',
        'NumeroMemoire' => 'N° MÉMOIRE / MEMORY No',
        'Client' => 'Noms du Client / Customer Name',
        'Agence' => 'Agence / Agency',
        'Contrat' => 'Réf. Client CMS / CMS Customer ID',
        'BillingDate' => 'Date Facture / Billing Date',
        'FACTURE' => 'No Facture / Bill ID No',
        'Compteur' => 'No Compteur / Meter No',
        'PreviousIndex' => 'Ancien INDEX / Previous Reading',
        'CurrentIndex' => 'Reading	Nouvel INDEX / Present Reading',
        'Consumption' => 'Consommation / Consumption(Kwh)',
        'Ratio' =>'Coefficient / Ratio',
        'MeterRent' =>'Location Compteur / Meter Rent',
        'HT' =>'Montant Total (HT) / Total Amount (Without Tax)',
        'TAX' =>'Taxes Totales / Total Tax',
        'TTC' =>'Montant Total (TTC) / Total Amount With Tax',
        'DueAmount' =>'Montant à Payer / Due Amount',
    ];

    // Texte pied de page
    public array $footer = [
        'Total' => 'TOTAL',
        'MontantTotal' => 'MONTANT TOTAL A PAYER TTC/ AMOUNT DUE WITH TAXES',
        'Signature' => 'Signature',
        'Energizing' => 'ENERGIZING CAMEROON',
        'Savoir' => 'Bon a savoir / Good to know',
        'InformationsFr' => 'Ce mémoire comprend l\'ensemble des factures individuelles émises ce mois et dont les installations sont rattachées à votre code regroupement. SVP bien vouloir signaler toute anomalie à votre gestionnaire de compte',
        'InformationsEn' => 'This momory contain the summary of indivual bills generated this month that are attached to your regrouping code. Please, do inform your account manager to all identified anomalies',
        'Informations' => 'FACTURE UNIQUE - NE PEUT SERVIR D\'ACQUIT / SINGLE COPY BILL - SHALL NOT SERVE AS RECEIPT',
    ];
}
