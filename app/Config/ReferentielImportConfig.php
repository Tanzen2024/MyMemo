<?php

namespace Config;

use App\Models\Referentiel\GenericReferentielModel;

class ReferentielImportConfig
{
    private const TABLE_NAME = 'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT';

    private const TRUNCATE_TABLES = [
        'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT',
        'CMSREPORT.TB_FACTURES_MEMOIRES',
        'CMSREPORT.TB_FACTURES_MEMOIRES_INFOS',
    ];

    private static function baseConfig(): array
    {
        return [
            'model' => GenericReferentielModel::class,
            'table' => self::TABLE_NAME,
            'truncate_tables' => self::TRUNCATE_TABLES,
            'allowed_fields' => ['CONTRAT', 'TUTELLE'],
            'map' => [0 => 'CONTRAT', 1 => 'TUTELLE'],
            'numeric_fields' => [],
            'required' => ['CONTRAT', 'TUTELLE'],
        ];
    }

    public static function get(): array
    {
        return [
            'agents_cde_camwater_contrat' => self::baseConfig(),
            'bureaux_cde_camwater_contrat' => self::baseConfig(),
            'agents_globeleq_contrat' => self::baseConfig(),
            'bureaux_globeleq_contrat' => self::baseConfig(),
            'grands_comptes_contrat' => self::baseConfig(),
            'regions_contrat' => self::baseConfig(),

            'agents_cde_camwater_facture' => self::baseConfig(),
            'bureaux_cde_camwater_facture' => self::baseConfig(),
            'agents_globeleq_facture' => self::baseConfig(),
            'bureaux_globeleq_facture' => self::baseConfig(),
            'grands_comptes_facture' => self::baseConfig(),
            'regions_facture' => self::baseConfig(),

            'postpaid_particulier_contrat' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_CONFACRESU',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_CONFACRESU',
                    'CMSREPORT.TB_FACTURES_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_MEMOIRES_INFOS',
                ],
                'allowed_fields' => ['CONFACRESU'],
                'map' => [0 => 'CONFACRESU'],
                'numeric_fields' => [],
                'required' => ['CONFACRESU'],
            ],

            'postpaid_particulier_facture' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_CONFACRESU',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_CONFACRESU',
                    'CMSREPORT.TB_FACTURES_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_MEMOIRES_INFOS',
                ],
                'allowed_fields' => ['CONFACRESU'],
                'map' => [0 => 'CONFACRESU'],
                'numeric_fields' => [],
                'required' => ['CONFACRESU'],
            ],

            'etat_bt_contrat' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT',
                    'CMSREPORT.TB_FACTURES_ETAT_POUR_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_ETAT_BASE_READINGS',
                    'CMSREPORT.TB_FACTURES_ETAT_READING_INFOS',
                ],
                'allowed_fields' => ['CONTRAT', 'TUTELLE'],
                'map' => [0 => 'CONTRAT', 1 => 'TUTELLE'],
                'numeric_fields' => [],
                'required' => ['CONTRAT', 'TUTELLE'],
            ],

            'etat_bt_facture' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_ETAT_WITH_FACTURE',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_ETAT_WITH_FACTURE',
                    'CMSREPORT.TB_FACTURES_ETAT_POUR_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_ETAT_BASE_READINGS',
                    'CMSREPORT.TB_FACTURES_ETAT_READING_INFOS',
                ],
                'allowed_fields' => ['FACTURE', 'TUTELLE'],
                'map' => [0 => 'FACTURE', 1 => 'TUTELLE'],
                'numeric_fields' => [],
                'required' => ['FACTURE', 'TUTELLE'],
            ],

            'etat_mt_contrat' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_ETAT_WITH_CONTRAT',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_4_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_READINGS',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_INFOS',
                ],
                'allowed_fields' => ['CONTRAT', 'TUTELLE'],
                'map' => [0 => 'CONTRAT', 1 => 'TUTELLE'],
                'numeric_fields' => [],
                'required' => ['CONTRAT', 'TUTELLE'],
            ],

            'etat_mt_facture' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_ETAT_WITH_FACTURE',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_ETAT_WITH_FACTURE',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_4_MEMOIRES',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_READINGS',
                    'CMSREPORT.TB_FACTURES_ETAT_MT_INFOS',
                ],
                'allowed_fields' => ['FACTURE', 'TUTELLE'],
                'map' => [0 => 'FACTURE', 1 => 'TUTELLE'],
                'numeric_fields' => [],
                'required' => ['FACTURE', 'TUTELLE'],
            ],

            'prepaid_contrat' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_CONFACRESU',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_CONFACRESU',
                    'CMSREPORT.TMP_DETAILS_4_MEMOIRES_PREPAID',
                ],
                'allowed_fields' => ['CONFACRESU'],
                'map' => [0 => 'CONFACRESU'],
                'numeric_fields' => [],
                'required' => ['CONFACRESU'],
            ],

            'prepaid_recu' => [
                'model' => GenericReferentielModel::class,
                'table' => 'CMSREPORT.TMP_REF_CONFACRESU',
                'truncate_tables' => [
                    'CMSREPORT.TMP_REF_CONFACRESU',
                    'CMSREPORT.TMP_DETAILS_4_MEMOIRES_PREPAID',
                ],
                'allowed_fields' => ['CONFACRESU'],
                'map' => [0 => 'CONFACRESU'],
                'numeric_fields' => [],
                'required' => ['CONFACRESU'],
            ],
        ];
    }

    // Méthode pour obtenir les tables à vider
    public static function getTruncateTablesForType(string $type): array
    {
        $config = self::get();

        return $config[$type]['truncate_tables'] ?? []; // Retourne un tableau vide si le type n'existe pas
    }

    public static function isValidType(string $type): bool
    {
        // Clés valides
        $validKeys = array_keys(self::get());

        // Vérification de la validité
        return in_array($type, $validKeys);
    }
}