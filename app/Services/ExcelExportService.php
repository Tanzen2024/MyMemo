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
    /** Au-delà de ce nombre de lignes, exportToExcelAuto() saute la bordure
     *  de corps de tableau (cosmétique) pour rester dans un temps d'exécution
     *  sûr — voir le commentaire à son point d'utilisation. */
    private const MAX_ROWS_FOR_BODY_BORDERS = 15000;

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

        // Un titre de feuille invalide (caractères \ / ? * [ ] ou > 31 car.)
        // fait lever une exception PhpSpreadsheet avant même l'écriture des
        // données ; $regroupName vient d'un champ libre du formulaire, donc
        // sanitisé ici comme le fait déjà exportMemoirePostpaid() ailleurs
        // dans le projet, avec la même règle.
        $sheetTitle = preg_replace('/[\\\\\/\*\[\]\:\?]/', '', $regroupName) ?? '';
        $sheetTitle = substr(trim($sheetTitle), 0, 31) ?: 'Export';
        $sheet->setTitle($sheetTitle);

        $headers = array_keys(reset($data));
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        // 🔹 HEADERS
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($this->styleHeader());

        // 🔹 DATA — écriture en bloc (fromArray) plutôt que cellule par
        // cellule : sur un jeu de données large (dizaines de milliers de
        // lignes, ex. export "Données Mémoires" sans filtre de période),
        // l'ancienne boucle setCellValue() par cellule devenait extrêmement
        // lente (mesuré : non linéaire, plusieurs minutes au-delà de ~20 000
        // lignes), au point de risquer une interruption de la requête HTTP
        // avant la fin de l'écriture du fichier — laissant un .xlsx tronqué
        // et donc invalide pour Excel. fromArray() reste largement plus
        // rapide à cette échelle.
        $rows = [];
        foreach ($data as $row) {
            $rows[] = array_map(static fn ($key) => $row[$key] ?? '', $headers);
        }
        $sheet->fromArray($rows, null, 'A2');
        $rowNum = count($rows) + 1;

        // fromArray() utilise l'auto-détection standard de PhpSpreadsheet,
        // qui interpréterait une valeur commençant par =, +, - ou @ comme une
        // formule (CWE-1236) : on ne repasse explicitement en TYPE_STRING que
        // les rares cellules concernées plutôt que de refaire un passage
        // setCellValue() complet sur toutes les cellules.
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $value) {
                if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@") !== false) {
                    $cell = Coordinate::stringFromColumnIndex($j + 1) . ($i + 2);
                    $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        unset($rows);

        // Appliquer une bordure à CHAQUE cellule d'une très large plage est
        // une opération PhpSpreadsheet mesurée comme non linéaire à cette
        // échelle (~70s pour 60 000 lignes x 27 colonnes à elle seule) :
        // au-delà d'un volume raisonnable, ce style purement cosmétique est
        // sauté pour ne pas risquer de dépasser le temps d'exécution/la
        // patience du navigateur et produire un fichier tronqué. Le contenu
        // des données n'est pas affecté, seule la bordure de tableau l'est.
        if ($rowNum - 1 <= self::MAX_ROWS_FOR_BODY_BORDERS) {
            $sheet->getStyle("A2:{$lastCol}{$rowNum}")
                  ->applyFromArray($this->styleBody());
        } else {
            log_message('info', "exportToExcelAuto: bordures de corps de tableau ignorées ({$rowNum} lignes > " . self::MAX_ROWS_FOR_BODY_BORDERS . "), export volumineux.");
        }

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
