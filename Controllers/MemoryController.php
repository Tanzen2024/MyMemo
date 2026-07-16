<?php

namespace App\Controllers;

require_once ROOTPATH . 'vendor/autoload.php';

use App\Controllers\BaseController;
use App\Services\ExcelExportService;
use App\Services\ExcelImportService;
use App\Services\OracleService;
use App\Factories\MemorySQLFactory;
use Config\ReferentielImportConfig;
use CodeIgniter\HTTP\IncomingRequest;

class MemoryController extends BaseController
{
    protected OracleService $oracle;
    protected ExcelImportService $excelImporter;
    protected ExcelExportService $excelExporter;
    protected array $configMap = [];

    public function __construct()
    {
        // Initialisation services
        $this->oracle = new OracleService();
        $this->excelImporter = new ExcelImportService();
        $this->excelExporter = new ExcelExportService($this->oracle);

        // Init Oracle pour MemorySQLFactory
        MemorySQLFactory::initOracle($this->oracle);

        // Charger configuration globale
        $this->configMap = ReferentielImportConfig::get();

        log_message('debug', 'MemoryController initialisé avec types : ' . implode(', ', array_keys($this->configMap)));
    }

    /**
     * Point d'entrée import/export unifié
     * Renvoie JSON avec success, type, context et fichiers
     */
    public function importAndExport()
    {
        $context = null;
        $type    = null;

        try {
            set_time_limit(300);
            ini_set('memory_limit', '1024M');

            log_message('debug', 'POST data : ' . print_r($this->request->getPost(), true));

            // Déterminer le type et le contexte
            $result = $this->excelImporter->determineReferentielType($this->request);
            $context = $result['context'];
            $type    = $result['type'];

            log_message('debug', "Contexte={$context}, Type={$type}");

            // Importer le fichier Excel
            $importData = $this->importProcess($this->request, $context, $type);
            log_message('debug', 'Import terminé : ' . json_encode($importData));

            // Lancer le processus mémoire Oracle
            MemorySQLFactory::runMemoryProcess(
                $type,
                [
                    'year' => $importData['year'],
                    'cycle' => $importData['cycle'],
                    'regroup' => $importData['regroupName'],
                    'DEBUT' => $importData['dateDebut'],
                    'FIN' => $importData['dateFin'],
                ],
                'loading'
            );

            // Générer les requêtes SQL
            $queries = MemorySQLFactory::build(
                $type,
                [
                    'year' => $importData['year'],
                    'cycle' => $importData['cycle'],
                    'regroup' => $importData['regroupName'],
                    'DEBUT' => $importData['dateDebut'],
                    'FIN' => $importData['dateFin'],
                ],
                'generation'
            );

            $exportDir = WRITEPATH . 'exports/';
            if (!is_dir($exportDir)) mkdir($exportDir, 0777, true);
            $this->cleanupOldExports($exportDir, 6 * 3600);

            $generated = [];

            // === Export DonneesMemoires ===
            if (in_array('donnees_memoires', $importData['documentTypes'] ?? [], true)) {
                $data = $this->oracle->fetchAll($queries[0]['sql'], $queries[0]['binds']);
                if ($data) {
                    $file = $exportDir . 'DonneesMemoire_' . date('Ymd_His') . '.xlsx';
                    $this->excelExporter->exportToExcelAuto($file, $data, $importData['regroupName']);
                    $generated[] = $file;
                    log_message('debug', "Fichier DonneesMemoire généré : {$file}");
                } else {
                    log_message('error', "Aucune donnée pour DonneesMemoire");
                }
            }

            // === Export Memoire ===
            if (in_array('memoires', $importData['documentTypes'] ?? [], true)) {
                $rows = $this->oracle->fetchAll($queries[1]['sql'], $queries[1]['binds']);
                if ($rows) {
                    $filePath = $exportDir . 'Memoire_' . date('Ymd_His') . '.xlsx';
                    $this->exportMemoireCustomDirect($rows, $importData['regroupName'], $filePath);
                    $generated[] = $filePath;
                    log_message('debug', "Fichier Memoire généré : {$filePath}");
                } else {
                    log_message('error', "Aucune donnée pour Memoire");
                }
            }

            if (!$generated) {
                throw new \Exception('Aucun fichier généré');
            }

            // Retour JSON
            return $this->response->setJSON([
                'success' => true,
                'type' => $type,
                'context' => $context,
                'files' => $generated
            ]);

        } catch (\Throwable $e) {
            log_message('error', "Erreur : {$e->getMessage()}");
            log_message('error', $e->getTraceAsString());

            return $this->response->setJSON([
                'success' => false,
                'type' => $type,
                'context' => $context,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* ========================================= */
    /* Fonctions auxiliaires */
    /* ========================================= */

    protected function importProcess(IncomingRequest $request, string $context, string $type): array
    {
        $params = $this->resolveContextParams($request, $context, $type);

        if (!$params['file'] || !$params['file']->isValid()) {
            throw new \Exception('Fichier Excel invalide');
        }

        if (!$this->oracle->truncateReferentielType($type)) {
            throw new \Exception("Erreur TRUNCATE {$type}");
        }

        $this->excelImporter->import($type, $params['file']->getTempName(), $this->oracle);

        return array_merge($params, ['type' => $type, 'context' => $context]);
    }

    protected function resolveContextParams(IncomingRequest $request, string $context, string $type): array
    {
        if (!isset($this->configMap[$type])) {
            throw new \InvalidArgumentException("Type non trouvé : {$type}");
        }

        $suffix = $context;
        $fixedRegroup = str_contains($type, 'etat') ? 'ETAT' : trim((string)$request->getPost("regroupName_{$suffix}"));

        return [
            'context' => $context,
            'type' => $type,
            'regroupName' => $fixedRegroup,
            'year' => trim((string)$request->getPost("year_{$suffix}")),
            'cycle' => trim((string)$request->getPost("cycle_{$suffix}")),
            'documentTypes' => $request->getPost("document_type_{$suffix}") ?? [],
            'dateDebut' => $request->getPost("date_start_{$suffix}"),
            'dateFin' => $request->getPost("date_end_{$suffix}"),
            'file' => $request->getFile("referentiel_file_{$suffix}")
        ];
    }

    protected function exportMemoireCustomDirect(array $rows, string $regroupName, string $filePath): void
    {
        $config = new \Config\MemoirePostpaidExcel();

        // === COULEURS & STYLES ===
        $fillHeaderColor = 'D3D3D3';
        $fillRowGray     = 'F2F2F2';
        $borderColor     = '014BAA';
        $textBlueColor   = '014BA0';

        $maxLinesPerPage = 40;

        // === CONVERSION MONTANT EN LETTRES ===
        $nombreEnLettres = function (float $nombre): string {
            $formatter = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);
            return strtoupper($formatter->format($nombre) . ' FRANCS CFA');
        };

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($regroupName);

        // === MISE EN PAGE ===
        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setScale(60)
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

        $spreadsheet->getDefaultStyle()
            ->getFont()->setName('Arial Narrow')->setSize(9);

        // === COLONNES ===
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
            'K'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'L'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
            'M'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
            'N'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
            'O'=>['width'=>20,'align'=>\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,'format'=>'#,##0'],
        ];
        foreach ($colSettings as $col => $s) {
            $sheet->getColumnDimension($col)->setWidth($s['width']);
        }

        // === MAPPING SQL → EXCEL ===
        $map = [
            'A' => 'CUSTOMER_NAME',
            'B' => 'AGENCY',
            'C' => 'SERVICE_NO',
            'D' => 'BILLING_DATE',
            'E' => 'BILL_NO',
            'F' => 'METER_NO',
            'G' => 'PREV_ACTUAL_READ',
            'H' => 'HHT_CURRENT_INDEX',
            'I' => 'CONSUMPTION_BILLED',
            'J' => 'COEFF',
            'K' => 'METER_RENT',
            'L' => 'AMOUNT_WITHOUT_VAT',
            'M' => 'AMOUNT_VAT',
            'N' => 'AMOUNT_WITH_TAX',
            'O' => 'DUE_AMOUNT'
        ];

        $chunks = array_chunk($rows, $maxLinesPerPage - 10);
        $totalPages = count($chunks);
        $currentPage = 1;
        $rowIndex = 11;

        $globalTotals = [
            'AMOUNT_WITHOUT_VAT' => 0,
            'AMOUNT_VAT'         => 0,
            'AMOUNT_WITH_TAX'    => 0,
            'DUE_AMOUNT'         => 0
        ];

        foreach ($chunks as $pageRows) {

            // === LOGO ===
            $logoPath = ROOTPATH . 'public/assets/images/eneo.jpg';
            if (is_file($logoPath)) {
                $logo = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $logo->setPath($logoPath);
                $logo->setCoordinates("A1");
                $logo->setResizeProportional(false);
                $logo->setWidth(223);
                $logo->setHeight(72);
                $logo->setWorksheet($sheet);
            }

            // === COMPANY INFO ===
            foreach ($config->companyInfo as $info) {
                if(isset($info['merge'])) $sheet->mergeCells($info['merge']);
                $sheet->setCellValue($info['cell'], $info['value']);
                $sheet->getStyle($info['cell'])->applyFromArray([
                    'font'=>['name'=>'Arial Narrow','size'=>$info['fontSize'] ?? 10,'bold'=>$info['bold'] ?? false,'color'=>['rgb'=>$info['fontColor'] ?? '000000']],
                    'alignment'=>['horizontal'=>$info['align'] ?? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT]
                ]);
            }

            // === HEADERS ADDITIONNELS ===
            foreach ($config->headersAdditionnal as $header) {
                if (isset($header['merge'])) $sheet->mergeCells($header['merge']);
                $sheet->setCellValue($header['cell'], $header['value']);

                $sheet->getStyle($header['cell'])->applyFromArray([
                    'font' => [
                        'bold' => $header['bold'] ?? false,
                        'italic' => $header['italic'] ?? false,
                        'size' => $header['fontSize'] ?? 10,
                        'color' => ['rgb' => $header['fontColor'] ?? '000000'],
                        'name' => 'Arial Narrow',
                    ],
                    'alignment' => [
                        'horizontal' => $header['align'] ?? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ]
                ]);
            }


            // === ENTETE TABLEAU ===
            $sheet->getStyle("A11:O11")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            foreach ($config->headers as $i => $label) {
                if (isset($columns[$i])) $sheet->setCellValue($columns[$i] . $rowIndex, $label);
            }

            $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray([
                'font'=>['bold'=>true, 'color'=>['rgb'=>$textBlueColor]],
                'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$fillHeaderColor]],
                'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
                'alignment'=>['horizontal'=>'center','wrapText'=>true]
            ]);
            $rowIndex++;

            // === DONNÉES ===
            $pageTotals = ['AMOUNT_WITHOUT_VAT'=>0,'AMOUNT_VAT'=>0,'AMOUNT_WITH_TAX'=>0,'DUE_AMOUNT'=>0];
            foreach ($pageRows as $i => $data) {
                foreach ($map as $col => $key) {
                    $sheet->setCellValue("{$col}{$rowIndex}", $data[$key] ?? '');
                    $sheet->getStyle("{$col}{$rowIndex}")->getAlignment()->setHorizontal($colSettings[$col]['align']);
                    if(isset($colSettings[$col]['format'])) {
                        $sheet->getStyle("{$col}{$rowIndex}")->getNumberFormat()->setFormatCode($colSettings[$col]['format']);
                    }
                }
                $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray([
                    'font' => ['size' => 10],
                    'borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['rgb'=>$borderColor]]],
                    'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$i%2===0?$fillRowGray:'FFFFFF']]
                ]);
                foreach ($pageTotals as $k=>$v) {
                    $pageTotals[$k]+= (float)($data[$k]??0);
                    $globalTotals[$k]+= (float)($data[$k]??0);
                }
                $rowIndex++;
            }

            // === SOUS-TOTAL PAGE ===
            $sheet->mergeCells("E{$rowIndex}:K{$rowIndex}");
            $sheet->setCellValue("E{$rowIndex}", "TOTAL");
            $sheet->setCellValue("L{$rowIndex}", $pageTotals['AMOUNT_WITHOUT_VAT']);
            $sheet->setCellValue("M{$rowIndex}", $pageTotals['AMOUNT_VAT']);
            $sheet->setCellValue("N{$rowIndex}", $pageTotals['AMOUNT_WITH_TAX']);
            $sheet->setCellValue("O{$rowIndex}", $pageTotals['DUE_AMOUNT']);
            $sheet->getStyle("E{$rowIndex}:O{$rowIndex}")->applyFromArray([
                'font'=>['bold'=>true,'color'=>['rgb'=>$textBlueColor]]
            ]);
            $rowIndex += 2;

            // === PIED DE PAGE ===
        foreach ($config->footers as $index => $line) {

            // Cas 4 : "ENERGIZING CAMEROON"
            if ($index === 3) {
                $sheet->mergeCells("M{$rowIndex}:O{$rowIndex}");
                $sheet->setCellValue("M{$rowIndex}", $line['value']);

                $sheet->getStyle("M{$rowIndex}")->applyFromArray([
                    'font' => [
                        'size' => 8,
                        'italic' => true,
                        'color' => ['rgb' => '014BA0'],
                        'name' => 'Arial Narrow',
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
                        'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    ]
                ]);

                $rowIndex++;
                continue;
            }

            // Cas 5 : "FACTURE UNIQUE ..." + pagination
            if ($index === 4) {
                $sheet->mergeCells("A{$rowIndex}:L{$rowIndex}");
                $sheet->setCellValue("A{$rowIndex}", $line['value']);

                $sheet->setCellValue("M{$rowIndex}", "Page {$currentPage} de {$totalPages}");
                $sheet->mergeCells("M{$rowIndex}:O{$rowIndex}");

                $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->applyFromArray([
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

                $sheet->getStyle("M{$rowIndex}:O{$rowIndex}")->applyFromArray([
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

                $rowIndex++;
                continue;
            }

            // Cas 1, 2, 3 : textes informatifs
            $sheet->mergeCells("A{$rowIndex}:L{$rowIndex}");
            $sheet->setCellValue("A{$rowIndex}", $line['value']);

            $style = [
                'font' => [
                    'size' => 9,
                    'name' => 'Arial Narrow',
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ]
            ];

            // Ligne 2 & 3 → surbrillance vert
            if ($index === 1 || $index === 2) {
                $style['fill'] = [
                    'fillType' => 'solid',
                    'startColor' => ['rgb' => 'DFF0D8']
                ];
            }

            // Ligne 3 → italic
            if ($index === 2) {
                $style['font']['italic'] = true;
                $style['font']['color']  = ['rgb' => '014BA0'];
            }

            $sheet->getStyle("A{$rowIndex}:O{$rowIndex}")->applyFromArray($style);

            // Cas 5 : textes informatifs
            if ($index === 5) {
                $sheet->mergeCells("M{$rowIndex}:O{$rowIndex}");
                $sheet->setCellValue("M{$rowIndex}", $line['value']);

                $style = [
                    'font' => [
                        'size' => 10,
                        'bold'=>true,
                        'name' => 'Arial Narrow',
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                        'wrapText'   => true,
                    ]
                ];
                $rowIndex++;
                continue;
            }

            $sheet->getStyle("M{$rowIndex}:O{$rowIndex}")->applyFromArray($style);

            $rowIndex++;
        }


            // === SAUT DE PAGE ADAPTÉ ===
            if ($currentPage < $totalPages) {
                $breakRow = $rowIndex - 1; // juste après le bloc de sous-total et avant le footer
                $sheet->setBreak("A{$breakRow}", \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
            }

            $currentPage++;
        }

        // === TOTAL FINAL ===
        $sheet->mergeCells("E{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("E{$rowIndex}", "MONTANT TOTAL A PAYER TTC / AMOUNT DUE WITH TAXES");
        $sheet->setCellValue("L{$rowIndex}", $globalTotals['AMOUNT_WITHOUT_VAT']);
        $sheet->setCellValue("M{$rowIndex}", $globalTotals['AMOUNT_VAT']);
        $sheet->setCellValue("N{$rowIndex}", $globalTotals['AMOUNT_WITH_TAX']);
        $sheet->setCellValue("O{$rowIndex}", $globalTotals['DUE_AMOUNT']);
        $rowIndex++;

        $sheet->mergeCells("E{$rowIndex}:O{$rowIndex}");
        $sheet->setCellValue("E{$rowIndex}", $nombreEnLettres($globalTotals['DUE_AMOUNT']));
        $sheet->getStyle("E{$rowIndex}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // === EXPORT FINAL ===
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($filePath);
    }
}
