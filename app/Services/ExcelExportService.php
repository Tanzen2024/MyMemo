<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ExcelExportService
{
    protected OracleService $oracle;

    public function __construct(OracleService $oracle)
    {
        $this->oracle = $oracle;
    }

    /**
     * Écrit une valeur issue d'Oracle/d'un import dans une cellule en empêchant
     * PhpSpreadsheet de l'auto-détecter comme une formule Excel (une chaîne
     * commençant par =, +, - ou @ serait sinon interprétée comme formule par
     * le DefaultValueBinder, y compris dans un fichier .xlsx natif).
     */
    private function setSafeCellValue($sheet, string $cell, $value): void
    {
        if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@") !== false) {
            $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    // =========================================================
    // 📊 EXPORT SIMPLE AUTO
    // =========================================================
    public function exportToExcelAuto(string $filePath, array $data, string $regroupName): void
    {
        if (empty($data)) {
            throw new \Exception('Aucune donnée à exporter');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($regroupName);

        $headers = array_keys(reset($data));

        // 🔹 HEADERS
        foreach ($headers as $i => $header) {
            $cell = Coordinate::stringFromColumnIndex($i + 1) . '1';
            $sheet->setCellValue($cell, $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($this->styleHeader());

        // 🔹 DATA
        $rowNum = 2;
        foreach ($data as $row) {
            foreach ($headers as $i => $key) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $rowNum;
                $this->setSafeCellValue($sheet, $cell, $row[$key] ?? '');
            }
            $rowNum++;
        }

        $sheet->getStyle("A2:{$lastCol}" . ($rowNum - 1))
              ->applyFromArray($this->styleBody());

        // 🔹 Largeur fixe : évite le coût de setAutoSize(true), qui doit
        // parcourir le contenu de chaque cellule pour estimer une largeur.
        for ($i = 1; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($i)
            )->setWidth(18);
        }

        (new Xlsx($spreadsheet))->save($filePath);
    }

    // =========================================================
    // 📄 EXPORT MÉMOIRE
    // =========================================================
    public function exportMemoireCustom(
        string $filePath,
        array $data,
        object $config,
        float $totalHT,
        float $totalTax,
        float $totalTTC,
        string $moisFacturation,
        string $dateEdition,
        string $numeroMemoire,
        string $regroupName
    ): void {
        if (empty($data)) {
            throw new \Exception('Aucune donnée mémoire');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($regroupName);

        $row = 1;

        // 🔹 TITRE
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", $config->headers['title'] ?? 'MEMOIRE');
        $sheet->getStyle("A{$row}")->applyFromArray($this->styleTitle());
        $row += 2;

        $sheet->setCellValue("A{$row}", 'Numéro :');
        $sheet->setCellValue("B{$row}", $numeroMemoire); $row++;

        $sheet->setCellValue("A{$row}", 'Période :');
        $sheet->setCellValue("B{$row}", $moisFacturation); $row++;

        $sheet->setCellValue("A{$row}", 'Date édition :');
        $sheet->setCellValue("B{$row}", $dateEdition); $row += 2;

        // 🔹 TABLE
        $headers = array_keys(reset($data));
        foreach ($headers as $i => $header) {
            $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
            $sheet->setCellValue($cell, $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")
              ->applyFromArray($this->styleHeader());

        $row++;

        foreach ($data as $line) {
            foreach ($headers as $i => $key) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
                $this->setSafeCellValue($sheet, $cell, $line[$key] ?? '');
            }
            $row++;
        }

        $sheet->getStyle("A5:{$lastCol}" . ($row - 1))
              ->applyFromArray($this->styleBody());

        // 🔹 TOTAUX
        $row++;
        $sheet->setCellValue("A{$row}", 'TOTAL HT');
        $sheet->setCellValue("B{$row}", $totalHT); $row++;

        $sheet->setCellValue("A{$row}", 'TOTAL TAX');
        $sheet->setCellValue("B{$row}", $totalTax); $row++;

        $sheet->setCellValue("A{$row}", 'TOTAL TTC');
        $sheet->setCellValue("B{$row}", $totalTTC);

        (new Xlsx($spreadsheet))->save($filePath);
    }

    // =========================================================
    // 🎨 STYLES
    // =========================================================
    protected function styleTitle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
    }

    protected function styleHeader(): array
    {
        return [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E9ECEF']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
    }

    protected function styleBody(): array
    {
        return [
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ]
        ];
    }
}
