<?php

namespace App\Controllers;

require_once ROOTPATH . 'vendor/autoload.php';

use App\Controllers\BaseController;
use App\Services\ExcelExportService;
use App\Services\ExcelImportService;
use App\Services\OracleService;
use App\Services\AuditLoggerService;
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
    protected array $configMap = [];

    const REGION = 'CENTRALIZED LV';

    public function __construct()
    {
        $this->oracle        = new OracleService();
        $this->excelImporter = new ExcelImportService();
        $this->excelExporter = new ExcelExportService($this->oracle);
        $this->auditLogger   = new AuditLoggerService();

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
    $operationStartedAt = microtime(true);
    try {
        // ⚡ Définir les caractères numériques pour cette session
        if (! $this->oracle->setNumericCharacters(',', ' ')) {
            log_message('error', 'Impossible de définir NLS_NUMERIC_CHARACTERS pour cette session.');
            return;
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

        log_message('debug', "Contexte en cours = {$context}, Type référentiel = {$type}");

        /* ============================
         * 2. Import Excel
         * ============================ */
        $importData = $this->importProcess($this->request, $context, $type);
        log_message('debug', 'Import terminé : ' . json_encode($importData));

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
        

        $generationStartedAt = microtime(true);
        $generationResult = MemorySQLFactory::runMemoryProcess(
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
        $this->auditLogger->log(
            'generate_memoires',
            ($generationResult['failed'] ?? 0) === 0 ? 'SUCCESS' : 'FAILED',
            microtime(true) - $generationStartedAt,
            $this->request,
            ['type' => $type]
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

        log_message('debug', "Fichier généré pour Mémoire : {$filePath}");
        $generated[] = $filePath;
    } else {
        log_message('error', 'Aucune donnée récupérée pour Mémoire.');
    }
        }


        if (!$generated) {
            throw new \Exception('Aucun fichier généré');
        }

        $this->auditLogger->log(
            'export_excel',
            'SUCCESS',
            microtime(true) - $operationStartedAt,
            $this->request,
            ['type' => $type, 'files' => count($generated)]
        );

        return $this->downloadFiles($generated, $exportDir, $importData);
    } catch (\Throwable $e) {
        $this->auditLogger->log(
            'sensitive_operation',
            'FAILED',
            microtime(true) - $operationStartedAt,
            $this->request,
            ['message' => $e->getMessage()]
        );
        log_message('error', 'Erreur : ' . $e->getMessage());
        log_message('error', 'Trace de l\'exception : ' . $e->getTraceAsString());
        return $this->response->setStatusCode(500)->setJSON([
            'error' => 'Une erreur est survenue pendant le traitement. Consultez les journaux serveur.'
        ]);
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

        $truncateStartedAt = microtime(true);
        if (!$this->oracle->truncateReferentielType($type)) {
            $this->auditLogger->log('truncate_temporary_tables', 'FAILED', microtime(true) - $truncateStartedAt, $request, ['type' => $type]);
            throw new \Exception("Erreur TRUNCATE {$type}");
        }
        $this->auditLogger->log('truncate_temporary_tables', 'SUCCESS', microtime(true) - $truncateStartedAt, $request, ['type' => $type]);
        
        $importStartedAt = microtime(true);
        try {
            $importResult = $this->excelImporter->import($type, $params['file']->getTempName(), $this->oracle);
        } catch (\Throwable $e) {
            $this->auditLogger->log('import_excel', 'FAILED', microtime(true) - $importStartedAt, $request, ['type' => $type]);
            throw $e;
        }
        $this->auditLogger->log(
            'import_excel',
            'SUCCESS',
            microtime(true) - $importStartedAt,
            $request,
            ['type' => $type, 'inserted' => $importResult['inserted'] ?? 0, 'skipped' => $importResult['skipped'] ?? 0]
        );

        return array_merge($params, ['type' => $type]);
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

    protected function computeTotals(array $rows): array
    {
        $ht = $tax = $ttc = 0;
        foreach ($rows as $r) {
            $ht  += (float)($r['HT'] ?? 0);
            $tax += (float)($r['TAX'] ?? 0);
            $ttc += (float)($r['TTC'] ?? 0);
        }
        return [$ht, $tax, $ttc];
    }

        protected function downloadFiles(array $files, string $dir, array $importData)
        {
            if (count($files) === 1) {

                return $this->response->download($files[0], null)
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

            return $this->response->download($zipPath, null)
                ->setFileName($zipName);
        }

    private function safeFilePart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? '';
        return trim($safe, '_') ?: 'export';
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
            $sheet->setCellValue($cell, $row[$header] ?? '');
            $col++;
        }

        $rowIndex++;
    }

    // =========================
    // 3. AUTO SIZE COLUMNS
    // =========================
    for ($i = 1; $i <= count($headers); $i++) {
        $colLetter = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }

    // =========================
    // 4. FREEZE HEADER
    // =========================
    $sheet->freezePane('A2');

    // =========================
    // 5. HEADER STYLE (OPTIONNEL MAIS PROPRE)
    // =========================
    $headerRange = 'A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1';

    $sheet->getStyle($headerRange)->getFont()->setBold(true);
    $sheet->getStyle($headerRange)->getAlignment()->setHorizontal('center');

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

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(false)
        ->setHorizontalCentered(true);

    $sheet->getPageMargins()
        ->setTop(0.6)
        ->setBottom(0.6)
        ->setLeft(0.3)
        ->setRight(0.3)
        ->setHeader(0.8)
        ->setFooter(0.8);

    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial Narrow')->setSize(9);

    $columns = range('A', 'O');
    $colSettings = [
        'A'=>['width'=>40,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'B'=>['width'=>40,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'C'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'D'=>['width'=>15,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'E'=>['width'=>15,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'F'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'0'],
        'G'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'H'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'I'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'J'=>['width'=>15,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'K'=>['width'=>30,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'L'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'M'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'N'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'O'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
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

    // ===== PARAMÈTRES DE PAGINATION =====
    $maxLinesPerPage = 30; // ajuster selon ton besoin
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

       /* ================= STYLE DYNAMIQUE ================= */

        $start = $rowIndex + 4;
        $currentRow = $start;

        foreach ($config->companyInfo as $info) {
            // 🔹 Style dynamique depuis config
            $sheet->getStyle("A{$currentRow}")->applyFromArray([
                'font' => [
                    'name'  => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);
        $sheet->setCellValue("G".($rowIndex + 6), $regroupId);
        $sheet->mergeCells("G".($rowIndex + 6).":I".($rowIndex + 6));
        $sheet->getStyle("G".($rowIndex + 6).":I".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $sheet->setCellValue("G".($rowIndex + 7), $regroupName);
        $sheet->mergeCells("G".($rowIndex + 7).":I".($rowIndex + 7));
        $sheet->getStyle("G".($rowIndex + 7).":I".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $readingCycleFormatted = str_pad($readingCycle, 2, '0', STR_PAD_LEFT);
        $sheet->setCellValue("N".($rowIndex + 6), $regroupId . " - " . $readingCycleFormatted . " - " . $calendarYear);
        $sheet->mergeCells("N".($rowIndex + 6).":O".($rowIndex + 6));
        $sheet->getStyle("N".($rowIndex + 6).":O".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);



        foreach ($config->headersAdditionnal as $header) {
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
                    'name'  => 'Arial Narrow',
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

        $rowIndex += 11;

        // === HEADER TABLEAU ===
        foreach ($config->headers as $i=>$label) {
            $col = $columns[$i];
            $sheet->setCellValue($col.$rowIndex, $label);
        }

        $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray([
            'font'=>['bold'=>true,'color'=>['rgb'=>$textBlueColor]],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$fillHeaderColor]],
            'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
            'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'wrapText'=>true]
        ]);
        $rowIndex++;

        log_message('debug', "Insertion données page {$currentPage} et ligne {$rowIndex}");

        // === DONNÉES + ZÉBRAGE + SOUS-TOTAL PAGE ===
        $pageTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0,'DUE_AMOUNT'=>0];
        foreach ($pageRows as $i=>$data) {
            foreach ($map as $col=>$field) {
                $sheet->setCellValue($col.$rowIndex, $data[$field] ?? 0);
                if(isset($colSettings[$col]['format'])){
                    $sheet->getStyle($col.$rowIndex)->getNumberFormat()->setFormatCode($colSettings[$col]['format']);
                }
                $sheet->getStyle($col.$rowIndex)->getAlignment()->setHorizontal($colSettings[$col]['align']);
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

        // === TOTAL PAGE ===
        $sheet->mergeCells("E{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("E{$rowIndex}", "TOTAL");
        $sheet->getStyle("E{$rowIndex}:K{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(false)
        ->setHorizontalCentered(true);

    $sheet->getPageMargins()
        ->setTop(0.6)
        ->setBottom(0.6)
        ->setLeft(0.3)
        ->setRight(0.3)
        ->setHeader(0.8)
        ->setFooter(0.8);

    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial Narrow')->setSize(9);

    $columns = range('A', 'N');
    $colSettings = [
        'A'=>['width'=>10,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'B'=>['width'=>25,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'C'=>['width'=>30,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'D'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'E'=>['width'=>45,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        'F'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'G'=>['width'=>25,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'H'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT],
        'I'=>['width'=>15,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'format'=>'#,##0'],
        'J'=>['width'=>30,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'K'=>['width'=>25,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        'L'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'M'=>['width'=>15,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        'N'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
    ];
    foreach ($colSettings as $col => $s) {
        $sheet->getColumnDimension($col)->setWidth($s['width']);
    }

    $map = [
        'A'=>'REGION','B'=>'DIVISION','C'=>'AGENCY','D'=>'SERVICE_NO','E'=>'CUSTOMER_NAME',
        'F'=>'REGISTRATION_NUMBER','G'=>'RECEIPT_NO','H'=>'METER_NO','I'=>'CONTINGENT','J'=>'TOKEN',
        'K'=>'TRANSACTION_DATE','L'=>'AMOUNT_WITHOUT_VAT','M'=>'AMOUNT_VAT','N'=>'AMOUNT_WITH_TAX'
    ];

    // ===== PARAMÈTRES DE PAGINATION =====
    $maxLinesPerPage = 40; // ajuster selon ton besoin
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


        $sheet->setCellValue("F".($rowIndex + 5), $regroupId);
        $sheet->mergeCells("F".($rowIndex + 5).":G".($rowIndex + 5));
        $sheet->getStyle("F".($rowIndex + 5).":G".($rowIndex + 5))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        $readingCycleFormatted = str_pad($readingCycle, 2, '0', STR_PAD_LEFT);
        $sheet->setCellValue("M".($rowIndex + 6), $regroupName . " - " . $readingCycleFormatted . " - " . $calendarYear);
        $sheet->mergeCells("M".($rowIndex + 6).":N".($rowIndex + 6));
        $sheet->getStyle("M".($rowIndex + 6).":N".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);

        if($regroupId === 'SONATREL'){
            $regroupId = 'M101512487290K';
        } else if ($regroupName === 'NHPC') {
            $regroupId = 'M071612552236J';
        }

        $sheet->setCellValue("F".($rowIndex + 6), $regroupId);
        $sheet->mergeCells("F".($rowIndex + 6).":G".($rowIndex + 6));
        $sheet->getStyle("F".($rowIndex + 6).":G".($rowIndex + 6))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
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

        $sheet->setCellValue("F".($rowIndex + 7), $regroupName);
        $sheet->mergeCells("F".($rowIndex + 7).":G".($rowIndex + 7));
        $sheet->getStyle("F".($rowIndex + 7).":G".($rowIndex + 7))->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '014BA0'],
                'name' => 'Arial Narrow',
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
            ],
        ]);


       /* ================= STYLE DYNAMIQUE ================= */

        $start = $rowIndex + 4;
        $currentRow = $start;

        foreach ($config->companyInfo as $info) {
            // 🔹 Style dynamique depuis config
            $sheet->getStyle("A{$currentRow}")->applyFromArray([
                'font' => [
                    'name'  => 'Arial Narrow',
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

        $sheet->setCellValue("E".($rowIndex + 1), $config->headersAdditionnal[0]['value']);
        $sheet->setCellValue("G".($rowIndex + 2), $config->headersAdditionnal[1]['value']);
        $sheet->setCellValue("E".($rowIndex + 5), $config->headersAdditionnal[2]['value']);
        $sheet->setCellValue("E".($rowIndex + 6), $config->headersAdditionnal[3]['value']);
        $sheet->setCellValue("E".($rowIndex + 7), $config->headersAdditionnal[4]['value']);
        $sheet->setCellValue("M".($rowIndex + 5), $config->headersAdditionnal[5]['value']);
        $sheet->setCellValue("J".($rowIndex + 7), $config->headersAdditionnal[6]['value']);
        $sheet->setCellValue("N".($rowIndex + 5), $config->headersAdditionnal[7]['value']);

        foreach ($config->headersAdditionnal as $header) {
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
                    'name'  => 'Arial Narrow',
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

        $rowIndex += 11;

        // === HEADER TABLEAU ===
        foreach ($config->headers as $i=>$label) {
            $col = $columns[$i];
            $sheet->setCellValue($col.$rowIndex, $label);
        }

        $sheet->getStyle("A{$rowIndex}:N{$rowIndex}")->applyFromArray([
            'font'=>['bold'=>true,'color'=>['rgb'=>$textBlueColor]],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$fillHeaderColor]],
            'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
            'alignment'=>['horizontal'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,'wrapText'=>true]
        ]);
        $rowIndex++;

        log_message('debug', "Insertion données page {$currentPage} et ligne {$rowIndex}");

        // === DONNÉES + ZÉBRAGE + SOUS-TOTAL PAGE ===
        $pageTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0];
        foreach ($pageRows as $i=>$data) {
            foreach ($map as $col=>$field) {
                $sheet->setCellValue($col.$rowIndex, $data[$field] ?? 0);
                if(isset($colSettings[$col]['format'])){
                    $sheet->getStyle($col.$rowIndex)->getNumberFormat()->setFormatCode($colSettings[$col]['format']);
                }
                $sheet->getStyle($col.$rowIndex)->getAlignment()->setHorizontal($colSettings[$col]['align']);
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

        // === TOTAL PAGE ===
        $sheet->mergeCells("F{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("F{$rowIndex}", "TOTAL");
        $sheet->getStyle("F{$rowIndex}:K{$rowIndex}")->applyFromArray([
            'font' => [
                'bold'=>true,
                'color'=>['rgb'=>$textBlueColor],
                'size' => 10,
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                    'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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
                            'name' => 'Arial Narrow',
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

    // ===== EXPORT =====
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($filePath);
}


}
