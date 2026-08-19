<?php

namespace App\Controllers;

require_once ROOTPATH . 'vendor/autoload.php';

use App\Controllers\BaseController;
use App\Services\ExcelExportService;
use App\Services\ExcelImportService;
use App\Services\OracleService;
use App\Services\AuditLoggerService;
use App\Services\AuditErrorClassifier;
use App\Services\ImportLockService;
use App\Exceptions\NoDataException;
use App\Exceptions\FileGenerationException;
use App\Factories\MemorySQLFactory;
use Config\ReferentielImportConfig;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class MemoryController extends BaseController
{
    protected OracleService $oracle;
    protected ExcelImportService $excelImporter;
    protected ExcelExportService $excelExporter;
    protected AuditLoggerService $auditLogger;
    protected ImportLockService $importLocks;
    protected array $configMap = [];

    const REGION = 'CENTRALIZED LV';

    /** Police unique pour tous les documents imprimés (remplace l'ancien "Arial Narrow"). */
    private const PRINT_FONT_NAME = 'Arial';

    /**
     * Hauteur de ligne (en points) utilisée pour estimer combien de lignes de
     * données tiennent sur une page A4 imprimée à 100% (voir computeMaxLinesPerPage()).
     * À ajuster si un test d'impression réel montre un écart.
     */
    private const PRINT_DATA_ROW_HEIGHT_PT = 15.0;

    /**
     * Nombre de lignes occupées par le bloc d'en-tête (société + titre +
     * libellés dynamiques) redessiné en haut de chaque page, avant la
     * première ligne de données (cf. le `$rowIndex += 11` des méthodes
     * exportMemoirePostpaid/exportMemoirePrepaid, +1 pour la ligne d'en-tête
     * du tableau elle-même).
     */
    private const PRINT_HEADER_BLOCK_ROWS = 12;

    /** Hauteur de ligne (points) du bloc d'en-tête, cf. renderCompanyInfoStyles(). */
    private const PRINT_HEADER_BLOCK_ROW_HEIGHT_PT = 16.0;

    /**
     * Hauteur (points) de la ligne d'en-tête du tableau (libellés de colonnes),
     * fixée explicitement car ces libellés sont désormais en wrapText sur des
     * colonnes resserrées : sans hauteur explicite, une ligne calée sur
     * PRINT_HEADER_BLOCK_ROW_HEIGHT_PT (prévue pour du texte sur une seule
     * ligne) couperait un libellé bilingue passé sur 2-3 lignes.
     */
    private const PRINT_TABLE_HEADER_ROW_HEIGHT_PT = 34.0;

    public function __construct()
    {
        $this->oracle        = new OracleService();
        $this->excelImporter = new ExcelImportService();
        $this->excelExporter = new ExcelExportService($this->oracle);
        $this->auditLogger   = new AuditLoggerService();
        $this->importLocks   = new ImportLockService();

        MemorySQLFactory::initOracle($this->oracle);

        // Charger la config globale
        $this->configMap = ReferentielImportConfig::get();

        log_message('debug', 'MemoryController initialisé avec les types : ' . implode(', ', array_keys($this->configMap)));
    }

    /**
     * Point d'entrée unifié import & export
     */
   public function importAndExport()
{
    $lock = null;
    $startedAt = microtime(true);
    $correlationId = AuditErrorClassifier::newCorrelationId();
    $classifier = new AuditErrorClassifier();
    OracleService::setCorrelationId($correlationId);

    // Journalise un échec classifié (import ou génération), avec référence
    // d'incident, puis renvoie l'utilisateur vers le formulaire d'origine
    // avec un message compréhensible au lieu d'une réponse JSON brute (le
    // formulaire est un POST classique, pas un appel AJAX).
    $failAndRedirect = function (string $event, string $module, \Throwable $e, float $stageStartedAt) use ($classifier, $correlationId) {
        $classified = $classifier->classify($e);
        $incidentRef = AuditErrorClassifier::newIncidentRef();

        log_message('error', "{$event} : " . $e->getMessage());
        log_message('error', 'Trace de l\'exception : ' . $e->getTraceAsString());

        $this->auditLogger->log($event, 'FAILED', microtime(true) - $stageStartedAt, $this->request, [
            'category'          => $classified['category'],
            'severity'          => $classified['severity'],
            'error_type'        => $classified['error_type'],
            'module'            => $module,
            'user_message'      => $classified['user_message'],
            'technical_message' => $classified['technical_message'],
            'exception'         => get_class($e),
            'file'              => $e->getFile() . ':' . $e->getLine(),
            'correlation_id'    => $correlationId,
            'incident_ref'      => $incidentRef,
        ]);

        return redirect()->back()->withInput()->with(
            'msg',
            $classified['user_message'] . ' Référence incident : ' . $incidentRef
        );
    };

    try {
        // ⚡ Définir les caractères numériques pour cette session
        if (! $this->oracle->setNumericCharacters(',', ' ')) {
            log_message('error', 'Impossible de définir NLS_NUMERIC_CHARACTERS pour cette session.');
            return $failAndRedirect('MEMORY_GENERATION_FAILED', 'Configuration', new \RuntimeException('ORA-00000 Impossible d\'initialiser la session Oracle.'), $startedAt);
        }

        // Augmenter le temps et la mémoire pour gros fichiers
        set_time_limit(1000);
        ini_set('memory_limit', '2048M');

        /* ============================
         * 1. Détection du contexte et type
         * ============================ */
        $result = $this->excelImporter->determineReferentielType($this->request);
        $context = $result['context'];
        $type    = $result['type'];

        $lock = $this->importLocks->acquire($type);
        if ($lock === null) {
            $this->auditLogger->log(
                'EXCEL_IMPORT_REFUSED',
                'REFUSED',
                microtime(true) - $startedAt,
                $this->request,
                [
                    'category'       => 'IMPORT',
                    'severity'       => 'WARNING',
                    'type'           => $type,
                    'module'         => $context,
                    'correlation_id' => $correlationId,
                    'message'        => 'Un import du même référentiel est déjà en cours.',
                ]
            );

            return redirect()->back()->withInput()->with(
                'msg',
                'Un import de ce référentiel est déjà en cours. Veuillez réessayer dans quelques instants.'
            );
        }

        log_message('debug', "Contexte en cours = {$context}, Type référentiel = {$type}");

        /* ============================
         * 2. Import Excel
         * ============================ */
        $importStartedAt = microtime(true);
        $this->auditLogger->log('EXCEL_IMPORT_STARTED', 'INFO', 0.0, $this->request, [
            'category'       => 'IMPORT',
            'severity'       => 'INFO',
            'type'           => $type,
            'module'         => $context,
            'correlation_id' => $correlationId,
        ]);

        try {
            $importData = $this->importProcess($this->request, $context, $type);
        } catch (\Throwable $e) {
            return $failAndRedirect('EXCEL_IMPORT_FAILED', $context, $e, $importStartedAt);
        }
        log_message('debug', 'Import terminé : ' . json_encode($importData));

        $this->auditLogger->log(
            'EXCEL_IMPORT_COMPLETED',
            'SUCCESS',
            microtime(true) - $importStartedAt,
            $this->request,
            [
                'category'       => 'IMPORT',
                'severity'       => 'SUCCESS',
                'type'           => $type,
                'module'         => $context,
                'correlation_id' => $correlationId,
                'file'     => $importData['file']?->getClientName() ?? 'inconnu',
                'inserted' => $importData['importStats']['inserted'] ?? null,
                'skipped'  => $importData['importStats']['skipped'] ?? null,
                'rows'     => $importData['importStats']['inserted'] ?? 0,
            ]
        );

        $exportStartedAt = microtime(true);
        $this->auditLogger->log('MEMORY_GENERATION_STARTED', 'INFO', 0.0, $this->request, [
            'category'       => 'MEMORY',
            'severity'       => 'INFO',
            'module'         => $context,
            'correlation_id' => $correlationId,
            'client'         => $importData['regroupName'] ?? '',
        ]);

        try {

        /* ============================
         * 3. Chargement Oracle
         * ============================ */

        log_message('debug', 'Démarrage du processus mémoire avec les paramètres : ' . json_encode([
            'year' => $importData['year'],
            'cycle' => $importData['cycle'],
            'regroup' => $importData['regroupName'],
            'DEBUT' => $importData['dateDebut'],
            'FIN' => $importData['dateFin'],
        ]));
        

        MemorySQLFactory::runMemoryProcess(
            $type,
            [
                'year'    => $importData['year'],
                'cycle'   => $importData['cycle'],
                'regroup' => $importData['regroupName'],
                'DEBUT'   => $importData['dateDebut'],
                'FIN'     => $importData['dateFin'],
            ],
            'loading'
        );

        /* ============================
         * 4. Génération SQL
         * ============================ */
        $queries = MemorySQLFactory::build(
            $type,
            [
                'year'    => $importData['year'],
                'cycle'   => $importData['cycle'],
                'regroup' => $importData['regroupName'],
                'DEBUT'   => $importData['dateDebut'],
                'FIN'     => $importData['dateFin'],
            ],
            'generation'
        );

        $exportDir = WRITEPATH . 'exports/';
        if (!is_dir($exportDir)) mkdir($exportDir, 0777, true);
        log_message('debug', "Répertoire d'exportation vérifié : {$exportDir}");
        $this->cleanupOldExports($exportDir, 6 * 3600);

        $generated = [];

        /* ============================
        * 5. Données mémoire
        * ============================ */
        if (in_array('donnees_memoires', $importData['documentTypes'] ?? [], true)) {

            $query = $queries['donnees_memoires'] ?? $queries[0];

            log_message('debug', 'DonneesMemoire SQL length: ' . strlen($query['sql']));

            $data = $this->oracle->fetchAll($query['sql'], $query['binds']);

            log_message('debug', 'DonneesMemoire result count: ' . count($data));
            // Ne pas logger les données brutes pour éviter fuite d'information
            $firstRowKeys = array_keys($data[0] ?? []);
            log_message('debug', 'DonneesMemoire first row keys: ' . implode(',', $firstRowKeys));

            if (!empty($data) && is_array($data)) {

                $file = $exportDir . 'DonneesMemoire_' . $this->safeFilePart($importData['regroupName']) . '_' . date('Ymd_His') . '.xlsx';

                $this->excelExporter->exportToExcelAuto(
                    $file,
                    $data,
                    $importData['regroupName']
                );

                $this->verifyGeneratedFile($file);
                log_message('debug', "Fichier généré pour DonneesMemoire : {$file}");

                $generated[] = $file;

            } else {
                log_message('error', 'Aucune donnée récupérée pour DonneesMemoire.');
            }
        }

        /* ============================
        * 6. Mémoire
        * ============================ */
        if (in_array('memoires', $importData['documentTypes'] ?? [], true)) {

    // Récupération des données depuis Oracle
    $rows = $this->oracle->fetchAll($queries[1]['sql'], $queries[1]['binds']);
    log_message('debug', 'Requête exécutée pour Mémoire : ' . json_encode($queries[1]));

    // Initialisation des variables à partir du premier enregistrement
    $calendarYear = '';
    $readingCycle = '';
    $regroupId    = '';
    $regroupName  = '';

        if (!empty($rows)) {
            $headerData = $rows[0] ?? [];
            // Logger uniquement les clés de l'en-tête pour éviter d'exposer des données
            $headerKeys = array_keys($headerData);
            log_message('debug', 'Header keys retrieved: ' . implode(',', $headerKeys));

        $calendarYear = $headerData['CALENDAR_YEAR'] ?? '';
        $readingCycle = $headerData['READING_CYCLE'] ?? '';
        $regroupId    = $headerData['REGROUP_ID'] ?? '';
        $regroupName  = $headerData['REGROUP_NAME'] ?? '';
    }

    // Valeurs par défaut si vides
    //$regroupId   = !empty(trim((string)$regroupId))   ? $regroupId   : ($importData['regroupName'] ?? 'CENTRALIZED LV');
    //$regroupName = !empty(trim((string)$regroupName)) ? $regroupName : ($importData['regroupName'] ?? 'CENTRALIZED LV');

    $regroupId   = $importData['regroupName'];
    $regroupName = $importData['regroupName'];


    if (!empty($rows)) {
        $exportDir = WRITEPATH . 'exports/';
        if (!is_dir($exportDir)) mkdir($exportDir, 0777, true);
        $this->cleanupOldExports($exportDir, 6 * 3600);

        $filePath = $exportDir . 'Memoire_' . $this->safeFilePart($importData['regroupName']) . '_' . date('Ymd_His') . '.xlsx';

        log_message('debug', 'Nom fichier mémoire généré : ' . $filePath);

        // Appel de la fonction d'export directe
        if ($context === 'prepaid') {
            $this->exportMemoirePrepaid($rows, $regroupName, $regroupId, $calendarYear, $readingCycle, $filePath);
        } else {
            if($type === 'etat_mt_contrat') {
                $this->exportPrintDataForMemoireMT($rows, $filePath);
            } else {
                 $this->exportMemoirePostpaid($rows, $context, $regroupName, $regroupId, $calendarYear, $readingCycle, $filePath);
            }
        }

        $this->verifyGeneratedFile($filePath);
        log_message('debug', "Fichier généré pour Mémoire : {$filePath}");
        $generated[] = $filePath;
    } else {
        log_message('error', 'Aucune donnée récupérée pour Mémoire.');
    }
        }


        if (!$generated) {
            // Un résultat Oracle vide peut être une absence légitime de
            // données OU la conséquence d'une requête ayant échoué (fetchAll()
            // retourne [] dans les deux cas) : getLastError() permet de
            // distinguer les deux causes pour l'utilisateur et l'audit.
            $lastOracleError = $this->oracle->getLastError();
            if ($lastOracleError !== null) {
                throw new \RuntimeException(
                    'ORA-' . ($lastOracleError['code'] ?? '00000') . ' ' . ($lastOracleError['message'] ?? 'Erreur Oracle')
                );
            }

            throw new NoDataException(
                'Aucune donnée n\'a été trouvée pour les critères sélectionnés (période, cycle ou regroupement).'
            );
        }

        $this->auditLogger->log(
            'MEMORY_GENERATION_COMPLETED',
            'SUCCESS',
            microtime(true) - $exportStartedAt,
            $this->request,
            [
                'category'       => 'MEMORY',
                'severity'       => 'SUCCESS',
                'type'           => $type,
                'module'         => $context,
                'client'         => $importData['regroupName'] ?? '',
                'correlation_id' => $correlationId,
                'files'          => array_map('basename', $generated),
                'file'           => basename($generated[0]),
            ]
        );

        return $this->downloadFiles($generated, $exportDir, $importData);

        } catch (\Throwable $e) {
            return $failAndRedirect('MEMORY_GENERATION_FAILED', $context, $e, $exportStartedAt);
        }
    } catch (\Throwable $e) {
        // Filet de sécurité pour toute erreur en dehors des deux étapes
        // instrumentées ci-dessus (détection du type, verrou d'import...).
        return $failAndRedirect('OPERATION_FAILED', $context ?? 'Import/Export', $e, $startedAt);
    } finally {
        OracleService::setCorrelationId(null);
        $lock?->release();
    }
}

    /**
     * Vérifie qu'un fichier annoncé comme généré existe réellement et n'est
     * pas vide avant de le considérer comme un succès (cf. audit : ne jamais
     * enregistrer SUCCESS sur la seule absence d'exception levée).
     */
    private function verifyGeneratedFile(string $path): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) === 0) {
            throw new FileGenerationException(
                "Le fichier généré est introuvable ou vide à l'emplacement attendu : {$path}"
            );
        }
    }

    /* ========================================================= */

    protected function importProcess(IncomingRequest $request, string $context, string $type): array
    {
        $params = $this->resolveContextParams($request, $context, $type);

        if (!$params['file'] || !$params['file']->isValid()) {
            throw new \Exception('Fichier Excel invalide');
        }

        $tablesToTruncate = ReferentielImportConfig::getTruncateTablesForType($type);
        if (!empty($tablesToTruncate)) {
            foreach ($tablesToTruncate as $table) {
                log_message('info', "Table à tronquer : {$table}");
            }
        } else {
            log_message('debug', "Aucune table à tronquer pour '{$type}' ou type inconnu.");
        }

        if (!$this->oracle->truncateReferentielType($type, $this->configMap)) {
            throw new \Exception("Erreur TRUNCATE {$type}");
        }
        
        // Oracle TRUNCATE implique un commit : la transaction protège donc les
        // insertions du référentiel, tandis que le verrou couvre tout le flux.
        $this->oracle->begin();
        try {
            $importStats = $this->excelImporter->import($type, $params['file']->getTempName(), $this->oracle);
            $this->oracle->commit();
        } catch (\Throwable $e) {
            $this->oracle->rollback();
            throw $e;
        }

        return array_merge($params, ['type' => $type, 'importStats' => $importStats]);
    }

    /* ========================================================= */

    /**
     * Résolution automatique des params depuis le type de config
     */
    protected function resolveContextParams(IncomingRequest $request, string $context, string $type): array
    {
        if (!isset($this->configMap[$type])) {
            throw new \InvalidArgumentException("Type non trouvé dans la config : {$type}");
        }
        
        $fixedRegroup = str_contains($type, 'etat') ? 'ETAT' : trim((string) $request->getPost("regroupName_{$context}"));

        return [
            'context'       => $context,
            'type'          => $type,
            'regroupName'   => $fixedRegroup,
            'year'          => trim((string) $request->getPost("year_{$context}")),
            'cycle'         => trim((string) $request->getPost("cycle_{$context}")),
            'documentTypes' => $request->getPost("document_type_{$context}") ?? [],
            'dateDebut'     => $request->getPost("date_start_{$context}"),
            'dateFin'       => $request->getPost("date_end_{$context}"),
            'file'          => $request->getFile("referentiel_file_{$context}"),
        ];
    }

    /* ========================================================= */

        protected function downloadFiles(array $files, string $dir, array $importData)
        {
            if (count($files) === 1) {

                // setMime=true : sans cela DownloadResponse envoie
                // Content-Type: application/octet-stream au lieu du type
                // Office Open XML attendu pour un .xlsx.
                return $this->response->download($files[0], null, true)
                    ->setFileName(basename($files[0]));
            }

            $zipName = 'Memoire_DonneesMemoires_' . $this->safeFilePart($importData['regroupName']) . '_' . date('Ymd_His') . '.zip';
            $zipPath = $dir . $zipName;

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create export archive.');
            }

            foreach ($files as $f) {
                $zip->addFile($f, basename($f));
            }

            $zip->close();

            return $this->response->download($zipPath, null, true)
                ->setFileName($zipName);
        }

    private function safeFilePart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';
        return trim($safe, '_') ?: 'export';
    }

    /**
     * Écrit une valeur potentiellement issue d'un import/d'Oracle dans une cellule
     * en empêchant PhpSpreadsheet de l'interpréter comme une formule Excel
     * (une chaîne commençant par =, +, - ou @ serait sinon auto-détectée comme
     * formule par le DefaultValueBinder, y compris dans un fichier .xlsx natif).
     */
    private function setSafeCellValue(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, $value): void
    {
        if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@") !== false) {
            $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    /**
     * Applique le format numérique et l'alignement (constants par colonne,
     * cf. $colSettings) une seule fois sur toute la plage de lignes d'une page,
     * au lieu de répéter l'appel à chaque cellule. Le résultat visuel est
     * identique ; seul le nombre d'appels à l'API de style change.
     */
    private function applyColumnStylesForRange(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $colSettings, int $firstRow, int $lastRow): void
    {
        if ($lastRow < $firstRow) {
            return;
        }

        foreach ($colSettings as $col => $s) {
            $range = "{$col}{$firstRow}:{$col}{$lastRow}";

            if (isset($s['format'])) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($s['format']);
            }

            $sheet->getStyle($range)->getAlignment()->setHorizontal($s['align']);
        }
    }

    /**
     * Configure une mise en page d'impression professionnelle pour un tableau
     * large destiné à être imprimé sur A4.
     *
     * Choix imposé : Paysage fixe (plus de largeur imprimable qu'en portrait,
     * donc une échelle FitToWidth plus lisible pour un tableau à colonnes
     * nombreuses), toutes les colonnes compressées sur une seule page de
     * largeur (FitToWidth = 1), hauteur libre sur autant de pages que
     * nécessaire (FitToHeight = 0). Excel calcule lui-même le facteur
     * d'échelle nécessaire ; voir estimateFitToWidthScalePercent() pour
     * l'estimation de ce même facteur côté PHP, utilisée pour recaler la
     * pagination verticale manuelle (computeMaxLinesPerPage()) sur le rendu
     * réellement compressé.
     *
     * @param array $colSettings Tableau des colonnes ['A' => ['width' => int, ...], ...]
     * @param array $margins     ['top' => float, 'bottom' => float, 'left' => float, 'right' => float, 'header' => float, 'footer' => float] en pouces
     *
     * @return string L'orientation retenue (\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_*),
     *                à réutiliser pour computeMaxLinesPerPage() et estimateFitToWidthScalePercent().
     */
    private function configurePrintLayout(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $colSettings, array $margins): string
    {
        $orientation = \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE;

        $sheet->getPageSetup()
            ->setOrientation($orientation)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setHorizontalCentered(true)
            ->setVerticalCentered(false);

        $sheet->getPageMargins()
            ->setTop($margins['top'])
            ->setBottom($margins['bottom'])
            ->setLeft($margins['left'])
            ->setRight($margins['right'])
            ->setHeader($margins['header'])
            ->setFooter($margins['footer']);

        // Grille masquée à l'impression, configuré explicitement plutôt que
        // de dépendre du réglage par défaut de PhpSpreadsheet.
        $sheet->setShowGridlines(false);
        $sheet->setPrintGridlines(false);

        // Zoom d'aperçu cohérent avec l'échelle d'impression réelle (100%).
        $sheet->getSheetView()->setZoomScale(100);

        return $orientation;
    }

    /**
     * Définit la zone d'impression une fois le contenu entièrement généré
     * (nécessaire car la dernière ligne n'est connue qu'à la fin de la
     * pagination). Sans cela, Excel utilise la zone utilisée par défaut, qui
     * peut inclure des artefacts (ex. objets flottants) au-delà du contenu réel.
     */
    private function finalizePrintArea(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$lastRow}");
    }

    /**
     * Estime le pourcentage d'échelle qu'Excel appliquera réellement à
     * l'impression pour satisfaire FitToWidth = 1 (cf. configurePrintLayout()),
     * c'est-à-dire le rapport entre la largeur imprimable de la page A4
     * (moins marges gauche/droite, selon l'orientation) et la largeur totale
     * des colonnes.
     *
     * PhpSpreadsheet ne calcule pas et ne stocke pas ce pourcentage (c'est
     * Excel qui le fait à l'ouverture du fichier) : on le ré-estime donc côté
     * PHP, avec la même conversion largeur de colonne → pixels qu'Excel
     * (7 px par unité de largeur + 5 px de marge interne par colonne, MDW=7
     * pour la police par défaut), afin de recaler computeMaxLinesPerPage()
     * sur le rendu réellement compressé plutôt que sur une échelle 100%
     * qui produirait des sauts de page prématurés (pages quasi vides).
     *
     * @param string $orientation \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_*
     */
    private function estimateFitToWidthScalePercent(array $colSettings, array $margins, string $orientation): float
    {
        $columnCount = count($colSettings);
        $totalWidthUnits = array_sum(array_column($colSettings, 'width'));

        $totalWidthPt = ($totalWidthUnits * 7 + $columnCount * 5) * 0.75;

        $pageWidthIn = $orientation === \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
            ? 11.69 // A4 paysage
            : 8.27;  // A4 portrait
        $pageWidthPt = $pageWidthIn * 72;
        $usableWidthPt = $pageWidthPt - ($margins['left'] + $margins['right']) * 72;

        if ($totalWidthPt <= 0.0) {
            return 100.0;
        }

        $scale = ($usableWidthPt / $totalWidthPt) * 100;

        // Bornes Excel réelles (10% à 400%), mais on ne monte jamais au-delà
        // de 100% : FitToWidth ne sert qu'à réduire, jamais à agrandir.
        return min(100.0, max(10.0, $scale));
    }

    /**
     * Estime combien de lignes de données tiennent sur une page A4 imprimée,
     * compte tenu de la hauteur du bloc d'en-tête déjà dessiné en haut de
     * page et de l'échelle réellement appliquée par FitToWidth = 1 (cf.
     * estimateFitToWidthScalePercent()) : plus l'échelle est réduite, plus
     * de lignes tiennent physiquement dans la même surface imprimable.
     *
     * @param string $orientation \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_*
     * @param float  $headerBlockHeightPt Hauteur estimée (en points, à 100%) du bloc d'en-tête
     *                                    redessiné en haut de chaque page (société, titre, etc.)
     * @param float  $scalePercent Échelle réelle d'impression (cf. estimateFitToWidthScalePercent()).
     */
    private function computeMaxLinesPerPage(string $orientation, float $headerBlockHeightPt, array $margins, float $scalePercent = 100.0): int
    {
        $pageHeightIn = $orientation === \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
            ? 8.27  // A4 paysage
            : 11.69; // A4 portrait

        $scaleFactor = max(0.1, $scalePercent / 100);

        $usableHeightPt = ($pageHeightIn - $margins['top'] - $margins['bottom'] - $margins['header'] - $margins['footer']) * 72;
        $availableForDataPt = max(0.0, $usableHeightPt - $headerBlockHeightPt * $scaleFactor);

        return max(5, (int) floor($availableForDataPt / (self::PRINT_DATA_ROW_HEIGHT_PT * $scaleFactor)));
    }

    /**
     * Bloc de style identique entre exportMemoirePostpaid et exportMemoirePrepaid :
     * applique le style dynamique du bloc "société" (colonne A uniquement,
     * indépendant du nombre de colonnes du tableau de données).
     */
    private function renderCompanyInfoStyles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $companyInfo, int $rowIndex): void
    {
        $start = $rowIndex + 4;
        $currentRow = $start;

        foreach ($companyInfo as $info) {
            $sheet->getStyle("A{$currentRow}")->applyFromArray([
                'font' => [
                    'name'  => self::PRINT_FONT_NAME,
                    'size'  => $info['fontSize'] ?? 10,
                    'bold'  => $info['bold'] ?? false,
                    'color' => ['rgb' => $info['fontColor'] ?? '000000']
                ],
                'alignment' => [
                    'horizontal' => $info['align'] ?? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ]
            ]);

            $sheet->getRowDimension($currentRow)->setRowHeight(16);

            $currentRow++;
        }

        // Première ligne légèrement plus grande
        $sheet->getStyle("A{$start}")->getFont()->setSize(11);
    }

    /**
     * Bloc de style identique entre exportMemoirePostpaid et exportMemoirePrepaid :
     * place et met en forme les en-têtes additionnels dynamiques définis en config
     * (cellule/fusion/police par entrée), indépendamment du nombre de colonnes.
     */
    private function renderAdditionalHeaders(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headersAdditionnal, int $rowIndex): void
    {
        foreach ($headersAdditionnal as $header) {
            preg_match('/([A-Z]+)(\d+)/', $header['cell'], $matches);
            $column  = $matches[1];
            $baseRow = (int) $matches[2];
            $newRow  = $rowIndex + ($baseRow - 1);
            $newCell = $column . $newRow;

            // Merge dynamique
            if (!empty($header['merge'])) {
                preg_match('/([A-Z]+)(\d+):([A-Z]+)(\d+)/', $header['merge'], $mergeMatch);
                $colStart = $mergeMatch[1];
                $colEnd   = $mergeMatch[3];
                $sheet->mergeCells("{$colStart}{$newRow}:{$colEnd}{$newRow}");

                // Appliquer le style sur toute la plage fusionnée
                $styleCell = "{$colStart}{$newRow}:{$colEnd}{$newRow}";
            } else {
                $styleCell = $newCell;
            }

            // Valeur
            $sheet->setCellValue($newCell, $header['value']);

            // Style de base
            $styleArray = [
                'font' => [
                    'name'  => self::PRINT_FONT_NAME,
                    'size'  => $header['fontSize'] ?? 10,
                    'bold'  => $header['bold'] ?? false,
                    'italic'=> $header['italic'] ?? false,
                    'color' => ['rgb' => $header['fontColor'] ?? '000000']
                ],
                'alignment' => [
                    'horizontal' => $header['align'] ?? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ]
            ];

            // Bordure uniquement si demandé
            if (!empty($header['border']) && $header['border'] === true) {
                $styleArray['borders'] = [
                    'outline' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => $header['borderColor'] ?? '014BA0'],
                    ],
                ];
            }

            // Appliquer le style sur la bonne plage
            $sheet->getStyle($styleCell)->applyFromArray($styleArray);
        }
    }

    protected function cleanupOldExports(string $dir, int $maxAge): void
    {
        foreach (glob($dir.'*.{xlsx,zip}', GLOB_BRACE) as $f) {
            if (time() - filemtime($f) > $maxAge) {
                @unlink($f);
            }
        }
    }


    private function formatMoisAnnee(int $annee, int $moisNumero): string
    {
        $mois = [
            1  => 'Janvier',
            2  => 'Février',
            3  => 'Mars',
            4  => 'Avril',
            5  => 'Mai',
            6  => 'Juin',
            7  => 'Juillet',
            8  => 'Août',
            9  => 'Septembre',
            10 => 'Octobre',
            11 => 'Novembre',
            12 => 'Décembre'
        ];

        $moisNom = $mois[$moisNumero] ?? '';

        return $moisNom && $annee ? $moisNom . ' ' . $annee : '';
    }

public function exportPrintDataForMemoireMT(array $rows, string $filePath) {
    if (empty($rows)) {
        log_message('error', 'Export MT: aucune donnée à exporter');
        return false;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('MEMOIRE_MT');

    // =========================
    // 1. HEADERS
    // =========================
    $headers = array_keys($rows[0]);
    $col = 1;

    foreach ($headers as $header) {
        $cell = Coordinate::stringFromColumnIndex($col) . '1';
        $sheet->setCellValue($cell, strtoupper($header));
        $col++;
    }

    // =========================
    // 2. DATA
    // =========================
    $rowIndex = 2;

    foreach ($rows as $row) {
        $col = 1;

        foreach ($headers as $header) {
            $cell = Coordinate::stringFromColumnIndex($col) . $rowIndex;
            $this->setSafeCellValue($sheet, $cell, $row[$header] ?? '');
            $col++;
        }

        $rowIndex++;
    }

    // =========================
    // 3. LARGEUR DE COLONNES
    // =========================
    // Largeur fixe au lieu de setAutoSize(true) : évite à PhpSpreadsheet de
    // parcourir le contenu de chaque cellule pour estimer une largeur, ce qui
    // devient coûteux sur les fichiers à beaucoup de lignes.
    $colSettings = [];
    for ($i = 1; $i <= count($headers); $i++) {
        $colLetter = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($colLetter)->setWidth(18);
        $colSettings[$colLetter] = ['width' => 18];
    }
    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

    // =========================
    // 4. FREEZE HEADER (écran) + RÉPÉTITION D'EN-TÊTE (impression)
    // =========================
    $sheet->freezePane('A2');
    // Contrairement à postpaid/prepaid (en-tête redessiné à chaque page), ce
    // tableau n'a qu'une seule ligne d'en-tête fixe : on utilise donc la
    // répétition native d'Excel pour qu'elle apparaisse sur chaque page imprimée.
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

    // =========================
    // 5. HEADER STYLE (OPTIONNEL MAIS PROPRE)
    // =========================
    $headerRange = 'A1:' . $lastColumn . '1';

    $sheet->getStyle($headerRange)->getFont()->setBold(true);
    $sheet->getStyle($headerRange)->getAlignment()->setHorizontal('center');

    // =========================
    // 5bis. MISE EN PAGE D'IMPRESSION
    // =========================
    $this->configurePrintLayout($sheet, $colSettings, [
        'top' => 0.6, 'bottom' => 0.6, 'left' => 0.5, 'right' => 0.5, 'header' => 0.3, 'footer' => 0.3,
    ]);
    $this->finalizePrintArea($sheet, $lastColumn, $rowIndex - 1);

    // =========================
    // 6. SAVE FILE
    // =========================
    $writer = new Xlsx($spreadsheet);
    $writer->save($filePath);

    // =========================
    // 7. DOWNLOAD BROWSER CI4
    // =========================
    return $this->response
        ->download($filePath, null)
        ->setFileName(basename($filePath));
}

protected function exportMemoirePostpaid(array $rows, string $context, string $regroupName, String $regroupId, int $calendarYear, int $readingCycle, string $filePath): void
{
    $config = new \Config\MemoirePostpaidExcel();

    // ===== CONFIG =====
    $fillHeaderColor = 'D3D3D3';
    $fillRowGray     = 'F2F2F2';
    $borderColor     = '014BAA';
    $textBlueColor   = '014BA0';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    /**
 * Cas spécial : Grand_compte
 * Une feuille par REGROUP_ID
 */

if ($context === 'postpaid_general' || $context === 'postpaid_etat') {

    $groupedRows = [];

    foreach ($rows as $row) {
        $groupKey = $row['REGROUP_ID'] ?? $row['REGROUP_NAME'] ?? 'UNKNOW';
        $groupedRows[$groupKey][] = $row;
    }

} else {

    // fonctionnement normal
    $groupedRows[$regroupId] = $rows;

}

$sheetIndex = 0;

foreach ($groupedRows as $groupId => $groupRows) {

    if ($sheetIndex === 0) {
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $sheet = $spreadsheet->createSheet();
    }

    /**
     * Nom de la feuille = regroup_id
     */

    $sheetName = $groupId ?: 'Sheet_' . $sheetIndex;

    // supprimer caractères interdits
    $sheetName = preg_replace('/[\\\\\\/\\*\\[\\]\\:\\?]/', '', $sheetName);

    // limiter à 31 caractères
    $sheetName = substr($sheetName, 0, 31);

    // éviter doublons
    if ($spreadsheet->sheetNameExists($sheetName)) {
        $sheetName .= '_' . $sheetIndex;
    }

    $sheet->setTitle($sheetName);

    // remplacer les variables pour la suite du code
    $rows = $groupRows;
    $regroupId = $groupId;
    $regroupName = $groupRows[0]['REGROUP_NAME'] ?? $groupId;

    $sheetIndex++;

    $spreadsheet->getDefaultStyle()->getFont()->setName(self::PRINT_FONT_NAME)->setSize(10);

    $columns = range('A', 'O');
    // Groupe A (contenu long : nom client, agence, n° compteur) volontairement
    // plus large que le Groupe B (références/dates/montants courts). Les
    // colonnes à FORMAT NUMÉRIQUE explicite (F='0', G/H/I/L/M/N/O='#,##0')
    // reçoivent en plus une marge de sécurité au-delà du nombre de chiffres
    // le plus long observé (cf. Memoire_JC_DECAUX_20260818_125414.xlsx) :
    // contrairement au texte qui déborde simplement visuellement, Excel
    // affiche "####" (jamais un chiffre tronqué) dès qu'une cellule numérique
    // formatée ne tient pas dans sa colonne — c'est ce qui provoquait les
    // "####" observés sur "No Compteur / Meter No" (13 chiffres, largeur
    // insuffisante à 13 sans aucune marge).
    $colSettings = [
        'A'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],   // Groupe A : Nom client
        'B'=>['width'=>16,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],   // Groupe A : Agence
        'C'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'D'=>['width'=>11,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'E'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'F'=>['width'=>16,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'0'], // Groupe A : No compteur (jusqu'à 13 chiffres + marge)
        'G'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'H'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'I'=>['width'=>9,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'J'=>['width'=>8,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'K'=>['width'=>10,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'L'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'M'=>['width'=>11,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'N'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'O'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
    ];
    foreach ($colSettings as $col => $s) {
        $sheet->getColumnDimension($col)->setWidth($s['width']);
    }

    $map = [
        'A'=>'CUSTOMER_NAME','B'=>'AGENCY','C'=>'SERVICE_NO',
        'D'=>'BILLING_DATE','E'=>'BILL_NO','F'=>'METER_NO',
        'G'=>'PREV_ACTUAL_READ','H'=>'HHT_CURRENT_INDEX','I'=>'CONSUMPTION_BILLED',
        'J'=>'COEFF','K'=>'METER_RENT','L'=>'AMOUNT_WITHOUT_VAT',
        'M'=>'AMOUNT_VAT','N'=>'AMOUNT_WITH_TAX','O'=>'DUE_AMOUNT'
    ];

    // ===== MISE EN PAGE D'IMPRESSION =====
    // Marges resserrées (dans la fourchette professionnelle habituelle) pour
    // récupérer de la largeur/hauteur imprimable au profit de l'échelle FitToWidth.
    $printMargins = ['top' => 0.45, 'bottom' => 0.45, 'left' => 0.35, 'right' => 0.35, 'header' => 0.3, 'footer' => 0.3];
    $printOrientation = $this->configurePrintLayout($sheet, $colSettings, $printMargins);
    $printScale = $this->estimateFitToWidthScalePercent($colSettings, $printMargins, $printOrientation);

    // ===== PARAMÈTRES DE PAGINATION =====
    // Bloc d'en-tête = 11 lignes "société/titre" à hauteur standard + 1 ligne
    // d'en-tête de tableau désormais plus haute (wrapText sur colonnes resserrées).
    $maxLinesPerPage = $this->computeMaxLinesPerPage(
        $printOrientation,
        (self::PRINT_HEADER_BLOCK_ROWS - 1) * self::PRINT_HEADER_BLOCK_ROW_HEIGHT_PT + self::PRINT_TABLE_HEADER_ROW_HEIGHT_PT,
        $printMargins,
        $printScale
    );
    $chunks = array_chunk($rows, $maxLinesPerPage);
    $totalPages = count($chunks);
    $globalTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0,'DUE_AMOUNT'=>0];

    $rowIndex = 1;
    $currentPage = 1;

    foreach ($chunks as $pageRows) {

        // === HEADER SOCIÉTÉ & LOGO ===
        $logoPath = ROOTPATH.'public/assets/images/socadel.jpg';
        if (is_file($logoPath)) {
            $logo = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $logo->setName('Logo');
            $logo->setDescription('Logo');
            $logo->setPath($logoPath);
            $logo->setCoordinates("A$rowIndex");
            $logo->setResizeProportional(false);
            $logo->setWidth(230);
            $logo->setHeight(65);
            $logo->setOffsetX(5);
            $logo->setOffsetY(5);
            $logo->setWorksheet($sheet);
        }

        $sheet->setCellValue("A".($rowIndex + 4), $config->companyInfo[0]['value']);
        $sheet->mergeCells("A".($rowIndex + 4).":B".($rowIndex + 4));
        $sheet->setCellValue("A".($rowIndex + 5), $config->companyInfo[1]['value']);
        $sheet->mergeCells("A".($rowIndex + 5).":B".($rowIndex + 5));
        $sheet->setCellValue("A".($rowIndex + 6), $config->companyInfo[2]['value']);
        $sheet->mergeCells("A".($rowIndex + 6).":B".($rowIndex + 6));
        $sheet->setCellValue("A".($rowIndex + 7), $config->companyInfo[3]['value']);
        $sheet->mergeCells("A".($rowIndex + 7).":B".($rowIndex + 7));
        $sheet->setCellValue("A".($rowIndex + 8), $config->companyInfo[4]['value']);
        $sheet->mergeCells("A".($rowIndex + 8).":B".($rowIndex + 8));
        $sheet->setCellValue("A".($rowIndex + 9), $config->companyInfo[5]['value']);
        $sheet->mergeCells("A".($rowIndex + 9).":B".($rowIndex + 9));

        $this->renderCompanyInfoStyles($sheet, $config->companyInfo, $rowIndex);

        $sheet->setCellValue("D".($rowIndex + 1), $config->headersAdditionnal[0]['value']);
        $sheet->setCellValue("F".($rowIndex + 2), $config->headersAdditionnal[1]['value']);
        $sheet->setCellValue("D".($rowIndex + 5), $config->headersAdditionnal[2]['value']);
        $sheet->setCellValue("D".($rowIndex + 6), $config->headersAdditionnal[3]['value']);
        $sheet->setCellValue("D".($rowIndex + 7), $config->headersAdditionnal[4]['value']);
        $sheet->setCellValue("K".($rowIndex + 5), $config->headersAdditionnal[5]['value']);
        $sheet->setCellValue("K".($rowIndex + 7), $config->headersAdditionnal[6]['value']);
        $sheet->setCellValue("N".($rowIndex + 5), $config->headersAdditionnal[7]['value']);

        $sheet->setCellValue("G".($rowIndex + 5), MemoryController::REGION);
        $sheet->mergeCells("G".($rowIndex + 5).":I".($rowIndex + 5));
        $sheet->getStyle("G".($rowIndex + 5).":I".($rowIndex + 5))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);
        $this->setSafeCellValue($sheet, "G".($rowIndex + 6), $regroupId);
        $sheet->mergeCells("G".($rowIndex + 6).":I".($rowIndex + 6));
        $sheet->getStyle("G".($rowIndex + 6).":I".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $this->setSafeCellValue($sheet, "G".($rowIndex + 7), $regroupName);
        $sheet->mergeCells("G".($rowIndex + 7).":I".($rowIndex + 7));
        $sheet->getStyle("G".($rowIndex + 7).":I".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $sheet->setCellValue("L".($rowIndex + 5), $this->formatMoisAnnee($calendarYear, $readingCycle));
        $sheet->mergeCells("L".($rowIndex + 5).":M".($rowIndex + 5));
        $sheet->getStyle("L".($rowIndex + 5).":M".($rowIndex + 5))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $dateDuJour = Time::now('Africa/Douala')->format('d/m/Y');
        $sheet->setCellValue("L".($rowIndex + 7), $dateDuJour);
        $sheet->mergeCells("L".($rowIndex + 7).":M".($rowIndex + 7));
        $sheet->getStyle("L".($rowIndex + 7).":M".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $readingCycleFormatted = str_pad($readingCycle, 2, '0', STR_PAD_LEFT);
        $this->setSafeCellValue($sheet, "N".($rowIndex + 6), $regroupId . " - " . $readingCycleFormatted . " - " . $calendarYear);
        $sheet->mergeCells("N".($rowIndex + 6).":O".($rowIndex + 6));
        $sheet->getStyle("N".($rowIndex + 6).":O".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            // Bordure assortie à celle du libellé "N° MÉMOIRE" juste au-dessus
            // (headersAdditionnal, cellule N6:O6) : le numéro forme ainsi un
            // bloc visuellement identifiable d'un seul coup d'œil.
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '014BA0'],
                ],
            ],
        ]);



        $this->renderAdditionalHeaders($sheet, $config->headersAdditionnal, $rowIndex);

        $rowIndex += 11;

        // === HEADER TABLEAU ===
        foreach ($config->headers as $i=>$label) {
            $col = $columns[$i];
            $sheet->setCellValue($col.$rowIndex, $label);
        }

        $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray([
            'font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$textBlueColor]],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$fillHeaderColor]],
            'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
            'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,'wrapText'=>true]
        ]);
        // Hauteur fixée explicitement : les libellés bilingues wrappent sur 2-3
        // lignes maintenant que les colonnes sont resserrées (cf. colSettings).
        $sheet->getRowDimension($rowIndex)->setRowHeight(self::PRINT_TABLE_HEADER_ROW_HEIGHT_PT);
        $rowIndex++;

        log_message('debug', "Insertion données page {$currentPage} et ligne {$rowIndex}");

        // === DONNÉES + ZÉBRAGE + SOUS-TOTAL PAGE ===
        $pageTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0,'DUE_AMOUNT'=>0];
        $pageDataFirstRow = $rowIndex;
        foreach ($pageRows as $i=>$data) {
            foreach ($map as $col=>$field) {
                $this->setSafeCellValue($sheet, $col.$rowIndex, $data[$field] ?? 0);
            }
            $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray([
                'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
                'fill'=>['fillType'=>'solid','startColor'=>['rgb'=> $i%2==0 ? $fillRowGray : 'FFFFFF']]
            ]);
            foreach ($pageTotals as $k=>$v) {
                $pageTotals[$k] += (float)($data[$k] ?? 0);
                $globalTotals[$k] += (float)($data[$k] ?? 0);
            }
            $rowIndex++;
        }
        // Format/alignement par colonne appliqués une seule fois sur toute la
        // page plutôt qu'à chaque cellule (voir applyColumnStylesForRange).
        $this->applyColumnStylesForRange($sheet, $colSettings, $pageDataFirstRow, $rowIndex - 1);

        // === TOTAL PAGE ===
        $sheet->mergeCells("E{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("E{$rowIndex}", "TOTAL");
        $sheet->getStyle("E{$rowIndex}:K{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("L{$rowIndex}", $pageTotals['AMOUNT_WITHOUT_VAT']);
        $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("L{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("M{$rowIndex}", $pageTotals['AMOUNT_VAT']);
        $sheet->getStyle("M{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("M{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("N{$rowIndex}", $pageTotals['AMOUNT_WITH_TAX']);
        $sheet->getStyle("N{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("N{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("O{$rowIndex}", $pageTotals['DUE_AMOUNT']);
        $sheet->getStyle("O{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("O{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);
        
        $rowIndex++;

        log_message(
            'debug',
            "Totaux page {$currentPage} => HT: {$pageTotals['AMOUNT_WITHOUT_VAT']} | TVA: {$pageTotals['AMOUNT_VAT']} | TTC: {$pageTotals['AMOUNT_WITH_TAX']}"
        );

        // === FOOTER SIMULÉ EN BAS ===

        // Vérifier si c’est la dernière page
        $isLastPage = $currentPage === $totalPages;

        $footerStart = $rowIndex + 3;
        $current = $footerStart;
        $signatureStart = $rowIndex;
        $signatureEnd = $signatureStart + 5;

        if ($isLastPage) {
            $footerStart = $rowIndex + 5;
            $current = $footerStart;
            $signatureStart = $rowIndex + 2;
            $signatureEnd = $signatureStart + 5;
            
            // === TOTAL FINAL + MONTANT EN LETTRES ===
            $sheet->mergeCells("E{$rowIndex}:K{$rowIndex}");
            $sheet->setCellValue("E{$rowIndex}", "MONTANT TOTAL A PAYER TTC / AMOUNT DUE WITH TAXES");
            $sheet->getStyle("E{$rowIndex}:K{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("L{$rowIndex}", $globalTotals['AMOUNT_WITHOUT_VAT']);
            $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("L{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("M{$rowIndex}", $globalTotals['AMOUNT_VAT']);
            $sheet->getStyle("M{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("M{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("N{$rowIndex}", $globalTotals['AMOUNT_WITH_TAX']);
            $sheet->getStyle("N{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("N{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("O{$rowIndex}", $globalTotals['DUE_AMOUNT']);
            $sheet->getStyle("O{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("O{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $rowIndex++;

            log_message('debug', '==============================');
            log_message('debug', 'TOTAL GLOBAL');
            log_message('debug', 'HT  = '.$globalTotals['AMOUNT_WITHOUT_VAT']);
            log_message('debug', 'TVA = '.$globalTotals['AMOUNT_VAT']);
            log_message('debug', 'TTC = '.$globalTotals['AMOUNT_WITH_TAX']);
            log_message('debug', 'DUE = '.$globalTotals['DUE_AMOUNT']);
            log_message('debug', '==============================');



            $nombreEnLettres = function(float $nombre): string {
                $formatter = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);
                return strtoupper($formatter->format($nombre).' FRANCS CFA');
            };
            $sheet->mergeCells("E{$rowIndex}:O{$rowIndex}");
            $sheet->setCellValue("E{$rowIndex}", $nombreEnLettres($globalTotals['DUE_AMOUNT']));
            $sheet->getStyle("E{$rowIndex}:O{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);
        }

        log_message('debug', "Insertion footer à partir de ligne {$footerStart}");

        foreach ($config->footers as $index => $line) {

            switch ($index) {

                /* ==============================
                CAS 0 : Bon à savoir
                ============================== */
                case 0:

                    $sheet->mergeCells("A{$current}:L{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);
                    
                    log_message('debug', "Footer[0] ligne {$current} : {$line['value']}");

                    $current++;
                    break;

                /* ==============================
                CAS 1 : Ligne verte info
                ============================== */
                case 1:

                    $sheet->mergeCells("A{$current}:L{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'DFF0D8'],
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);

                    log_message('debug', "Footer[1] ligne {$current} (fond vert)");

                    $current++;
                    break;

                /* ==============================
                CAS 2 : Texte italic bleu
                ============================== */
                case 2:

                    $sheet->mergeCells("A{$current}:L{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'italic' => true,
                            'color' => ['rgb' => '014BA0'],
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'DFF0D8'],
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);

                    log_message('debug', "Footer[2] ligne {$current} (italic bleu)");

                    $current++;
                    break;

                /* ==============================
                CAS 3 : ENERGIZING CAMEROON (droite)
                ============================== */
                case 3:

                    $sheet->mergeCells("M{$current}:O{$current}");
                    $sheet->setCellValue("M{$current}", $line['value']);

                    $sheet->getStyle("M{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'italic' => true,
                            'color' => ['rgb' => '014BA0'],
                            'name' => self::PRINT_FONT_NAME,
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    log_message('debug', "Footer[3] ENERGIZING à M{$current}:O{$current}");

                    $current++;
                    break;

                /* ==============================
                CAS 4 : FACTURE UNIQUE + Pagination
                ============================== */
                case 4:

                    $sheet->mergeCells("A{$current}:L{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->mergeCells("M{$current}:O{$current}");
                    $sheet->setCellValue("M{$current}", "Page {$currentPage} sur {$totalPages}");

                    $sheet->getStyle("A{$current}:L{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'bold' => true,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    $sheet->getStyle("M{$current}:O{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'bold' => true,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    log_message(
                        'debug',
                        "Footer[4] Pagination ligne {$current} | Page {$currentPage}/{$totalPages}"
                    );

                    $current++;
                    break;

                /* ==============================
                CAS 5 : Signature (bloc vertical)
                ============================== */
                case 5:
                    // Fusionner les cellules
                    $sheet->mergeCells("M{$signatureStart}:O{$signatureEnd}");
                    $sheet->setCellValue("M{$signatureStart}", $line['value']);

                    // Appliquer le style avec bordures à la plage fusionnée
                    $sheet->getStyle("M{$signatureStart}:O{$signatureEnd}")->applyFromArray([
                        'font' => [
                            'size' => 10,
                            'bold' => true,
                            'color' => ['rgb' => '000000'],
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, // Type de bordure
                                'color' => ['argb' => 'FF000000'], // Couleur de la bordure (noir)
                            ],
                        ],
                    ]);

                    log_message(
                        'debug',
                        "Signature bloc de M{$signatureStart} à O{$signatureEnd}"
                    );
                    
                    break;
            }
        }

        $rowIndex = $rowIndex + 10;

        if($currentPage < $totalPages){
            log_message('debug', "Ajout saut de page après ligne {$current}");
            $sheet->setBreak("A$current", \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
        }
        $currentPage++;
    }

    // Zone d'impression définie une fois le contenu de CETTE feuille connu
    // (un "grand compte" peut générer une feuille par REGROUP_ID).
    $this->finalizePrintArea($sheet, 'O', $rowIndex);
}

    // ===== EXPORT =====
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($filePath);
}




protected function exportMemoirePrepaid(array $rows, string $regroupName, String $regroupId, int $calendarYear, int $readingCycle, string $filePath): void
{
    $config = new \Config\MemoirePrepaidExcel();

    // ===== CONFIG =====
    $fillHeaderColor = 'D3D3D3';
    $fillRowGray     = 'F2F2F2';
    $borderColor     = '014BAA';
    $textBlueColor   = '014BA0';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($regroupName);

    $spreadsheet->getDefaultStyle()->getFont()->setName(self::PRINT_FONT_NAME)->setSize(10);

    $columns = range('A', 'N');
    // Groupe A (contenu long : agence, nom client, n° compteur) plus large que
    // le Groupe B, même logique que exportMemoirePostpaid : les colonnes
    // numériques (I/L/M/N='#,##0', H=General sur des ID longs) reçoivent une
    // marge de sécurité au-delà du nombre de chiffres le plus long attendu,
    // pour éviter qu'Excel n'affiche "####" (comportement propre aux cellules
    // numériques, jamais aux cellules texte).
    $colSettings = [
        'A'=>['width'=>13,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'B'=>['width'=>14,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'C'=>['width'=>16,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],   // Groupe A : Agence
        'D'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'E'=>['width'=>22,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],   // Groupe A : Nom client
        'F'=>['width'=>13,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'G'=>['width'=>13,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'H'=>['width'=>17,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT],  // Groupe A : No compteur (jusqu'à 13 chiffres + marge)
        'I'=>['width'=>10,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'format'=>'#,##0'],
        'J'=>['width'=>16,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'K'=>['width'=>11,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'L'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'M'=>['width'=>11,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'N'=>['width'=>12,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
    ];
    foreach ($colSettings as $col => $s) {
        $sheet->getColumnDimension($col)->setWidth($s['width']);
    }

    $map = [
        'A'=>'REGION','B'=>'DIVISION','C'=>'AGENCY','D'=>'SERVICE_NO','E'=>'CUSTOMER_NAME',
        'F'=>'REGISTRATION_NUMBER','G'=>'RECEIPT_NO','H'=>'METER_NO','I'=>'CONTINGENT','J'=>'TOKEN',
        'K'=>'TRANSACTION_DATE','L'=>'AMOUNT_WITHOUT_VAT','M'=>'AMOUNT_VAT','N'=>'AMOUNT_WITH_TAX'
    ];

    // ===== MISE EN PAGE D'IMPRESSION =====
    $printMargins = ['top' => 0.45, 'bottom' => 0.45, 'left' => 0.35, 'right' => 0.35, 'header' => 0.3, 'footer' => 0.3];
    $printOrientation = $this->configurePrintLayout($sheet, $colSettings, $printMargins);
    $printScale = $this->estimateFitToWidthScalePercent($colSettings, $printMargins, $printOrientation);

    // ===== PARAMÈTRES DE PAGINATION =====
    $maxLinesPerPage = $this->computeMaxLinesPerPage(
        $printOrientation,
        (self::PRINT_HEADER_BLOCK_ROWS - 1) * self::PRINT_HEADER_BLOCK_ROW_HEIGHT_PT + self::PRINT_TABLE_HEADER_ROW_HEIGHT_PT,
        $printMargins,
        $printScale
    );
    $chunks = array_chunk($rows, $maxLinesPerPage);
    $totalPages = count($chunks);
    $globalTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0];

    $rowIndex = 1;
    $currentPage = 1;

    foreach ($chunks as $pageRows) {

        // === HEADER SOCIÉTÉ & LOGO ===
        $logoPath = ROOTPATH.'public/assets/images/socadel.jpg';
        if (is_file($logoPath)) {
            $logo = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $logo->setName('Logo');
            $logo->setDescription('Logo');
            $logo->setPath($logoPath);
            $logo->setCoordinates("A$rowIndex");
            $logo->setResizeProportional(false);
            $logo->setWidth(230);
            $logo->setHeight(65);
            $logo->setOffsetX(5);
            $logo->setOffsetY(5);
            $logo->setWorksheet($sheet);
        }

        $sheet->setCellValue("A".($rowIndex + 4), $config->companyInfo[0]['value']);
        $sheet->mergeCells("A".($rowIndex + 4).":C".($rowIndex + 4));
        $sheet->setCellValue("A".($rowIndex + 5), $config->companyInfo[1]['value']);
        $sheet->mergeCells("A".($rowIndex + 5).":C".($rowIndex + 5));
        $sheet->setCellValue("A".($rowIndex + 6), $config->companyInfo[2]['value']);
        $sheet->mergeCells("A".($rowIndex + 6).":C".($rowIndex + 6));
        $sheet->setCellValue("A".($rowIndex + 7), $config->companyInfo[3]['value']);
        $sheet->mergeCells("A".($rowIndex + 7).":C".($rowIndex + 7));
        $sheet->setCellValue("A".($rowIndex + 8), $config->companyInfo[4]['value']);
        $sheet->mergeCells("A".($rowIndex + 8).":C".($rowIndex + 8));
        $sheet->setCellValue("A".($rowIndex + 9), $config->companyInfo[5]['value']);
        $sheet->mergeCells("A".($rowIndex + 9).":C".($rowIndex + 9));


        $this->setSafeCellValue($sheet, "F".($rowIndex + 5), $regroupId);
        $sheet->mergeCells("F".($rowIndex + 5).":G".($rowIndex + 5));
        $sheet->getStyle("F".($rowIndex + 5).":G".($rowIndex + 5))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $sheet->setCellValue("K".($rowIndex + 5), $this->formatMoisAnnee($calendarYear, $readingCycle));
        $sheet->mergeCells("K".($rowIndex + 5).":L".($rowIndex + 5));
        $sheet->getStyle("K".($rowIndex + 5).":L".($rowIndex + 5))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $dateDuJour = Time::now('Africa/Douala')->format('d/m/Y');
        $sheet->setCellValue("K".($rowIndex + 7), $dateDuJour);
        $sheet->mergeCells("K".($rowIndex + 7).":L".($rowIndex + 7));
        $sheet->getStyle("K".($rowIndex + 7).":L".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $readingCycleFormatted = str_pad($readingCycle, 2, '0', STR_PAD_LEFT);
        $this->setSafeCellValue($sheet, "M".($rowIndex + 6), $regroupName . " - " . $readingCycleFormatted . " - " . $calendarYear);
        $sheet->mergeCells("M".($rowIndex + 6).":N".($rowIndex + 6));
        $sheet->getStyle("M".($rowIndex + 6).":N".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            // Bordure assortie à celle du libellé "N° MÉMOIRE" juste au-dessus
            // (headersAdditionnal, cellule M6:N6) : le numéro forme ainsi un
            // bloc visuellement identifiable d'un seul coup d'œil.
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '014BA0'],
                ],
            ],
        ]);

        if($regroupId === 'SONATREL'){
            $regroupId = 'M101512487290K';
        } else if ($regroupName === 'NHPC') {
            $regroupId = 'M071612552236J';
        }

        $this->setSafeCellValue($sheet, "F".($rowIndex + 6), $regroupId);
        $sheet->mergeCells("F".($rowIndex + 6).":G".($rowIndex + 6));
        $sheet->getStyle("F".($rowIndex + 6).":G".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        if($regroupName === 'SONATREL'){
            $regroupName = 'RCYAO/2016/B1066';
        } else if ($regroupName === 'NHPC') {
            $regroupName = 'RC/YAO/2024/M/143';
        }

        $this->setSafeCellValue($sheet, "F".($rowIndex + 7), $regroupName);
        $sheet->mergeCells("F".($rowIndex + 7).":G".($rowIndex + 7));
        $sheet->getStyle("F".($rowIndex + 7).":G".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => self::PRINT_FONT_NAME,
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);


        $this->renderCompanyInfoStyles($sheet, $config->companyInfo, $rowIndex);

        $sheet->setCellValue("E".($rowIndex + 1), $config->headersAdditionnal[0]['value']);
        $sheet->setCellValue("G".($rowIndex + 2), $config->headersAdditionnal[1]['value']);
        $sheet->setCellValue("E".($rowIndex + 5), $config->headersAdditionnal[2]['value']);
        $sheet->setCellValue("E".($rowIndex + 6), $config->headersAdditionnal[3]['value']);
        $sheet->setCellValue("E".($rowIndex + 7), $config->headersAdditionnal[4]['value']);
        $sheet->setCellValue("M".($rowIndex + 5), $config->headersAdditionnal[5]['value']);
        $sheet->setCellValue("J".($rowIndex + 7), $config->headersAdditionnal[6]['value']);
        $sheet->setCellValue("N".($rowIndex + 5), $config->headersAdditionnal[7]['value']);

        $this->renderAdditionalHeaders($sheet, $config->headersAdditionnal, $rowIndex);

        $rowIndex += 11;

        // === HEADER TABLEAU ===
        foreach ($config->headers as $i=>$label) {
            $col = $columns[$i];
            $sheet->setCellValue($col.$rowIndex, $label);
        }

        $sheet->getStyle("A{$rowIndex}:N{$rowIndex}")->applyFromArray([
            'font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$textBlueColor]],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$fillHeaderColor]],
            'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
            'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'vertical'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,'wrapText'=>true]
        ]);
        // Hauteur fixée explicitement : les libellés bilingues wrappent sur 2-3
        // lignes maintenant que les colonnes sont resserrées (cf. colSettings).
        $sheet->getRowDimension($rowIndex)->setRowHeight(self::PRINT_TABLE_HEADER_ROW_HEIGHT_PT);
        $rowIndex++;

        log_message('debug', "Insertion données page {$currentPage} et ligne {$rowIndex}");

        // === DONNÉES + ZÉBRAGE + SOUS-TOTAL PAGE ===
        $pageTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0];
        $pageDataFirstRow = $rowIndex;
        foreach ($pageRows as $i=>$data) {
            foreach ($map as $col=>$field) {
                $this->setSafeCellValue($sheet, $col.$rowIndex, $data[$field] ?? 0);
            }
            $sheet->getStyle("A{$rowIndex}:N{$rowIndex}")->applyFromArray([
                'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
                'fill'=>['fillType'=>'solid','startColor'=>['rgb'=> $i%2==0 ? $fillRowGray : 'FFFFFF']]
            ]);
            foreach ($pageTotals as $k=>$v) {
                $pageTotals[$k] += (float)($data[$k] ?? 0);
                $globalTotals[$k] += (float)($data[$k] ?? 0);
            }
            $rowIndex++;
        }
        // Format/alignement par colonne appliqués une seule fois sur toute la
        // page plutôt qu'à chaque cellule (voir applyColumnStylesForRange).
        $this->applyColumnStylesForRange($sheet, $colSettings, $pageDataFirstRow, $rowIndex - 1);

        // === TOTAL PAGE ===
        $sheet->mergeCells("F{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("F{$rowIndex}", "TOTAL");
        $sheet->getStyle("F{$rowIndex}:K{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("L{$rowIndex}", $pageTotals['AMOUNT_WITHOUT_VAT']);
        $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("L{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("M{$rowIndex}", $pageTotals['AMOUNT_VAT']);
        $sheet->getStyle("M{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("M{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $sheet->setCellValue("N{$rowIndex}", $pageTotals['AMOUNT_WITH_TAX']);
        $sheet->getStyle("N{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("N{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => self::PRINT_FONT_NAME,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                    'color' => ['argb'=>$textBlueColor],
                ]
            ],
        ]);

        $rowIndex++;

        log_message(
            'debug',
            "Totaux page {$currentPage} => HT: {$pageTotals['AMOUNT_WITHOUT_VAT']} | TVA: {$pageTotals['AMOUNT_VAT']} | TTC: {$pageTotals['AMOUNT_WITH_TAX']}"
        );

        // === FOOTER SIMULÉ EN BAS ===

        // Vérifier si c’est la dernière page
        $isLastPage = $currentPage === $totalPages;

        $footerStart = $rowIndex + 3;
        $current = $footerStart;
        $signatureStart = $rowIndex;
        $signatureEnd = $signatureStart + 5;

        if ($isLastPage) {
            $footerStart = $rowIndex + 5;
            $current = $footerStart;
            $signatureStart = $rowIndex + 2;
            $signatureEnd = $signatureStart + 5;
            
            // === TOTAL FINAL + MONTANT EN LETTRES ===
            $sheet->mergeCells("F{$rowIndex}:K{$rowIndex}");
            $sheet->setCellValue("F{$rowIndex}", "MONTANT TOTAL A PAYER TTC / AMOUNT DUE WITH TAXES");
             $sheet->setCellValue("F{$rowIndex}", "TOTAL");
            $sheet->getStyle("F{$rowIndex}:K{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("L{$rowIndex}", $globalTotals['AMOUNT_WITHOUT_VAT']);
            $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("L{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("M{$rowIndex}", $globalTotals['AMOUNT_VAT']);
            $sheet->getStyle("M{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("M{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $sheet->setCellValue("N{$rowIndex}", $globalTotals['AMOUNT_WITH_TAX']);
            $sheet->getStyle("N{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("N{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);

            $rowIndex++;

            log_message('debug', '==============================');
            log_message('debug', 'TOTAL GLOBAL');
            log_message('debug', 'HT  = '.$globalTotals['AMOUNT_WITHOUT_VAT']);
            log_message('debug', 'TVA = '.$globalTotals['AMOUNT_VAT']);
            log_message('debug', 'TTC = '.$globalTotals['AMOUNT_WITH_TAX']);
            log_message('debug', '==============================');



            $nombreEnLettres = function(float $nombre): string {
                $formatter = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);
                return strtoupper($formatter->format($nombre).' FRANCS CFA');
            };
            $sheet->mergeCells("F{$rowIndex}:N{$rowIndex}");
            $sheet->setCellValue("F{$rowIndex}", $nombreEnLettres($globalTotals['AMOUNT_WITH_TAX']));
            $sheet->getStyle("F{$rowIndex}:N{$rowIndex}")->applyFromArray([
                'font' => [
                    'bold'=>true,
                    'color'=>['rgb'=>$textBlueColor],
                    'size' => 10,
                    'name' => self::PRINT_FONT_NAME,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, // Type de bordure
                        'color' => ['argb'=>$textBlueColor],
                    ]
                ],
            ]);
        }

        log_message('debug', "Insertion footer à partir de ligne {$footerStart}");

        foreach ($config->footers as $index => $line) {

            switch ($index) {

                /* ==============================
                CAS 0 : Bon à savoir
                ============================== */
                case 0:

                    $sheet->mergeCells("A{$current}:K{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);
                    
                    log_message('debug', "Footer[0] ligne {$current} : {$line['value']}");

                    $current++;
                    break;

                /* ==============================
                CAS 1 : Ligne verte info
                ============================== */
                case 1:

                    $sheet->mergeCells("A{$current}:K{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'DFF0D8'],
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);

                    log_message('debug', "Footer[1] ligne {$current} (fond vert)");

                    $current++;
                    break;

                /* ==============================
                CAS 2 : Texte italic bleu
                ============================== */
                case 2:

                    $sheet->mergeCells("A{$current}:K{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->getStyle("A{$current}")->applyFromArray([
                        'font' => [
                            'size' => 9,
                            'italic' => true,
                            'color' => ['rgb' => '014BA0'],
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'DFF0D8'],
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true,
                        ]
                    ]);

                    log_message('debug', "Footer[2] ligne {$current} (italic bleu)");

                    $current++;
                    break;

                /* ==============================
                CAS 3 : ENERGIZING CAMEROON (droite)
                ============================== */
                case 3:

                    $sheet->mergeCells("L{$current}:N{$current}");
                    $sheet->setCellValue("L{$current}", $line['value']);

                    $sheet->getStyle("L{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'italic' => true,
                            'color' => ['rgb' => '014BA0'],
                            'name' => self::PRINT_FONT_NAME,
                            'bold' => true,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    log_message('debug', "Footer[3] ENERGIZING à N{$current}:P{$current}");

                    $current++;
                    break;

                /* ==============================
                CAS 4 : FACTURE UNIQUE + Pagination
                ============================== */
                case 4:

                    $sheet->mergeCells("A{$current}:K{$current}");
                    $sheet->setCellValue("A{$current}", $line['value']);

                    $sheet->mergeCells("L{$current}:N{$current}");
                    $sheet->setCellValue("L{$current}", "Page {$currentPage} de {$totalPages}");

                    $sheet->getStyle("A{$current}:K{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'bold' => true,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    $sheet->getStyle("L{$current}:N{$current}")->applyFromArray([
                        'font' => [
                            'size' => 8,
                            'bold' => true,
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        ]
                    ]);

                    log_message(
                        'debug',
                        "Footer[4] Pagination ligne {$current} | Page {$currentPage}/{$totalPages}"
                    );

                    $current++;
                    break;

                /* ==============================
                CAS 5 : Signature (bloc vertical)
                ============================== */
                case 5:

                    // Fusionner les cellules
                    $sheet->mergeCells("L{$signatureStart}:N{$signatureEnd}");
                    $sheet->setCellValue("L{$signatureStart}", $line['value']);

                    // Appliquer le style avec bordures à la plage fusionnée
                    $sheet->getStyle("L{$signatureStart}:N{$signatureEnd}")->applyFromArray([
                        'font' => [
                            'size' => 10,
                            'bold' => true,
                            'color' => ['rgb' => '014BA0'],
                            'name' => self::PRINT_FONT_NAME,
                        ],
                        'alignment' => [
                            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, // Type de bordure
                                'color' => ['argb' => 'FF000000'], // Couleur de la bordure (noir)
                            ],
                        ],
                    ]);

                    log_message(
                        'debug',
                        "Signature bloc de L{$signatureStart} à N{$signatureEnd}"
                    );
                                    
                    break;
            }
        }

        $rowIndex = $rowIndex + 10;

        if($currentPage < $totalPages){
            log_message('debug', "Ajout saut de page après ligne {$current}");
            $sheet->setBreak("A$current", \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
        }
        $currentPage++;
    }

    $this->finalizePrintArea($sheet, 'N', $rowIndex);

    // ===== EXPORT =====
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($filePath);
}


}
