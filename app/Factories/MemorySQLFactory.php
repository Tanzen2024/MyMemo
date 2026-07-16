<?php

namespace App\Factories;

use App\Models\SQL\LoadingQueriesForMemoryPostpaidModel as PostpaidModel;
use App\Models\SQL\LoadingQueriesForMemoryRegionsModel as RegionModel;
use App\Models\SQL\LoadingQueriesForMemoryEtatModel as EtatModel;
use App\Models\SQL\LoadingQueriesForMemoryPrepaidModel as PrepaidModel;
use Config\ReferentielImportConfig;
use App\Services\OracleService;

class MemorySQLFactory
{
    protected static OracleService $oracle;

    /**
     * Définition des types de traitement et des builders associés
     * loading : toujours exécuté
     * generation : exécuté selon le choix utilisateur
     */
    protected static array $types = [
    // Particulier
    'postpaid_particulier_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_PARTICULIER',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_PARTICULIER',
        ],
        'generation' => [
            'generateDatasMemoire',
            'generateMemoire',
        ],
    ],

    'postpaid_particulier_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_PARTICULIER',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_PARTICULIER',
        ],
        'generation' => [
            'generateDatasMemoire',
            'generateMemoire',
        ],
    ],

    'agents_cde_camwater_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL',
        ],
        'generation' => [
            'AGENTS_CDE_CAMWATER',
            'generateMemoire',
        ],
    ],

    'bureaux_cde_camwater_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL',
        ],
        'generation' => [
            'BUREAUX_CDE_CAMWATER',
            'generateMemoire',
        ],
    ],

    'agents_globeleq_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL',
        ],
        'generation' => [
            'AGENTS_GLOBELEQ',
            'generateMemoire',
        ],
    ],

    'bureaux_globeleq_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL',
        ],
        'generation' => [
            'BUREAUX_GLOBELEQ',
            'generateMemoire',
        ],
    ],

    'grands_comptes_contrat' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_CONTRAT_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_ONLY_WITH_CONTRAT_GENERAL',
        ],
        'generation' => [
            'GRANDS_COMPTES',
            'generateMemoire',
        ],
    ],

    'agents_cde_camwater_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL',
        ],
        'generation' => [
            'AGENTS_CDE_CAMWATER',
            'generateMemoire',
        ],
    ],

    'bureaux_cde_camwater_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL',
        ],
        'generation' => [
            'BUREAUX_CDE_CAMWATER',
            'generateMemoire',
        ],
    ],

    'agents_globeleq_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL',
        ],
        'generation' => [
            'AGENTS_GLOBELEQ',
            'generateMemoire',
        ],
    ],

    'bureaux_globeleq_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL',
        ],
        'generation' => [
            'BUREAUX_GLOBELEQ',
            'generateMemoire',
        ],
    ],

    'grands_comptes_facture' => [
        'model' => PostpaidModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_MEMOIRES_ONLY_WITH_FACTURE_GENERAL',
            'LOADING_TB_FACTURES_MEMOIRES_INFOS_WITH_FACTURE_GENERAL',
        ],
        'generation' => [
            'GRANDS_COMPTES',
            'generateMemoire',
        ],
    ],

	
	'regions_contrat' => [
        'model' => RegionModel::class,
        'loading' => [
            'CMSREPORT.TB_FACTURES_MEMOIRES_REGIONS_2_GENERAL',
            'CMSREPORT.TB_FACTURES_REGIONS_INFOS_NEW_GENERAL',
        ],
        'generation' => [
            'generateDatasMemoire',
            'generateMemoire',
        ],
    ],
	
	'regions_facture' => [
        'model' => RegionModel::class,
        'loading' => [
            'CMSREPORT.TB_FACTURES_MEMOIRES_REGIONS_2_GENERAL',
            'CMSREPORT.TB_FACTURES_REGIONS_INFOS_NEW_GENERAL',
        ],
        'generation' => [
            'generateDatasMemoire',
            'generateMemoire',
        ],
    ],

    // Etat
    'etat_bt_contrat' => [
        'model' => EtatModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_ETAT_POUR_MEMOIRES_WITH_CONTRAT',
            'LOADING_TB_FACTURES_ETAT_BASE_READINGS',
            'LOADING_TB_FACTURES_ETAT_READING_INFOS',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRE_BT',
            'GENERATE_MEMOIRE_BT',
        ],
    ],

    'etat_bt_facture' => [
        'model' => EtatModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_ETAT_POUR_MEMOIRES_WITH_FACTURE',
            'LOADING_TB_FACTURES_ETAT_BASE_READINGS',
            'LOADING_TB_FACTURES_ETAT_READING_INFOS',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRE_BT',
            'GENERATE_MEMOIRE_MEMOIRE_BT',
        ],
    ],

    'etat_mt_contrat' => [
        'model' => EtatModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_ETAT_MT_4_MEMOIRES_WITH_CONTRAT',
            'LOADING_TB_FACTURES_ETAT_MT_READING',
            'LOADING_TB_FACTURES_ETAT_MT_INFOS',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRE_MT',
            'GENERATE_DATA_PRINT_MT'
        ],
    ],

    'etat_mt_facture' => [
        'model' => EtatModel::class,
        'loading' => [
            'LOADING_TB_FACTURES_ETAT_MT_4_MEMOIRES_WITH_FACTURE',
            'LOADING_TB_FACTURES_ETAT_MT_READING',
            'LOADING_TB_FACTURES_ETAT_MT_INFOS',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRE_MT',
            'GENERATE_DATA_PRINT_MT'
        ],
    ],

    // Prepaid
    'prepaid_contrat' => [
        'model' => PrepaidModel::class,
        'loading' => [
            'LOADING_TMP_DETAILS_4_MEMOIRES_PREPAID_WITH_CONTRAT_ONLY',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRES_FOR_PREPAID',
            'GENERATE_MEMOIRES_FOR_PREPAID',
        ],
    ],

    'prepaid_recu' => [
        'model' => PrepaidModel::class,
        'loading' => [
            'LOADING_TMP_DETAILS_4_MEMOIRES_PREPAID_WITH_RECU_ONLY',
        ],
        'generation' => [
            'GENERATE_DATA_MEMOIRES_FOR_PREPAID',
            'GENERATE_MEMOIRES_FOR_PREPAID',
        ],
    ],
];

    /**
     * Initialisation du service Oracle
     */
    public static function initOracle(OracleService $oracle): void
    {
        self::$oracle = $oracle;
    }

    /**
     * Construit les requêtes SQL selon le type et la section (loading / generation)
     */
    public static function build(string $type, array $params = [], string $section = 'all'): array
    {
        if (!isset(self::$types[$type])) {
            throw new \InvalidArgumentException("Type inconnu : {$type}");
        }

        
        $config = self::$types[$type];
    
        $model  = $config['model'];

        log_message('debug', "Configuration pour le type '{$type}': " . json_encode($config));
        log_message('debug', "Modèle utilisé : {$model}");

        // Déterminer quelles sections exécuter
        $sectionsToRun = [];
        if ($section === 'all') {
            $sectionsToRun = ['loading', 'generation'];
        } elseif (in_array($section, ['loading', 'generation'])) {
            $sectionsToRun = [$section];
        } else {
            throw new \InvalidArgumentException("Section invalide : {$section}");
        }

        $queries = [];

        foreach ($sectionsToRun as $sec) {
            foreach ($config[$sec] as $method) {
                if (!method_exists($model, $method)) {
                    throw new \RuntimeException("Méthode {$method} inexistante dans {$model}");
                }

                // --- Passage correct des arguments pour Particulier ---
                if (ReferentielImportConfig::isValidType($type)) {
                    $year    = $params['year'] ?? '';
                    $cycle   = $params['cycle'] ?? '';
                    $regroup = $params['regroup'] ?? '';
                    $DEBUT   = $params['DEBUT'] ?? '';
                    $FIN     = $params['FIN'] ?? '';
                    $result  = $model::$method($year, $cycle, $regroup, $DEBUT, $FIN);
                } else {
                    $result = $model::$method($params);
                }

                // Normalisation en tableau de requêtes
                if (is_string($result)) {
                    $queries[] = ['sql' => $result, 'binds' => []];
                } elseif (isset($result['sql'])) {
                    $queries[] = $result;
                } elseif (is_array($result)) {
                    foreach ($result as $q) {
                        if (is_string($q)) {
                            $queries[] = ['sql' => $q, 'binds' => []];
                        } else {
                            $queries[] = $q;
                        }
                    }
                } else {
                    throw new \RuntimeException("Résultat du builder {$method} invalide");
                }
            }
        }

        // Heuristique : détecter les placeholders sans binds et des variables PHP interpolées
        foreach ($queries as $i => $q) {
            $sqlText = $q['sql'] ?? '';
            $binds   = $q['binds'] ?? [];
            if (!is_array($binds)) {
                $binds = [];
            }

            if (preg_match_all('/:([a-zA-Z0-9_]+):/', $sqlText, $matches)) {
                foreach (array_unique($matches[1]) as $placeholder) {
                    if (!array_key_exists($placeholder, $binds)) {
                        log_message('warning', "MemorySQLFactory: requête #{$i} pour type '{$type}' contient le placeholder :{$placeholder}: sans bind correspondant.");
                    }
                }
            }

            if (empty($binds) && is_string($sqlText)) {
                $suspectPatterns = [
                    '/\$[a-zA-Z_][a-zA-Z0-9_]*/', // $var
                    '/\.[\s]*\$[a-zA-Z_]/', // . $var
                ];
                foreach ($suspectPatterns as $pat) {
                    if (preg_match($pat, $sqlText)) {
                        log_message('warning', "MemorySQLFactory: requête #{$i} pour type '{$type}' semble contenir des variables non liées; envisager d'utiliser des binds.");
                        break;
                    }
                }
            }
        }

        return $queries;
    }

    /**
     * Exécute les requêtes SQL séquentiellement
     */
    public static function runMemoryProcess(string $type, array $params = [], string $section = 'all'): array
    {
        if (!isset(self::$oracle)) {
            throw new \RuntimeException('Service Oracle non initialisé.');
        }

        log_message('debug', "Lancement MemorySQLFactory pour type={$type} avec section={$section} et params=" . json_encode($params));

        $queries = self::build($type, $params, $section);

        $results = [
            'type'    => $type,
            'section' => $section,
            'total'   => count($queries),
            'success' => 0,
            'failed'  => 0,
        ];

        foreach ($queries as $index => $q) {
            try {
                self::$oracle->executeSql($q['sql'], $q['binds'] ?? []);
                log_message('debug', "Requête #{$index} exécutée avec succès : {$q['sql']}");
                $results['success']++;
            } catch (\Exception $e) {
                log_message('error', "Erreur SQL sur requête #{$index} : " . $e->getMessage() . " | SQL : " . $q['sql']);
                $results['failed']++;
            }
        }

        log_message('debug', "MemorySQLFactory terminé pour type={$type}, section={$section} : {$results['success']}/{$results['total']} réussies, {$results['failed']} échouées");

        return $results;
    }
}
