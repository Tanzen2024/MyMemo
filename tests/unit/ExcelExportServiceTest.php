<?php

use App\Services\ExcelExportService;
use App\Services\OracleService;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Valide que exportToExcelAuto() ("Données Mémoires") produit réellement un
 * fichier XLSX valide : conteneur ZIP correct, structure Office Open XML
 * minimale présente, rechargeable par PhpSpreadsheet, avec les données
 * attendues. Couvre aussi la sécurité (injection de formule) et la
 * robustesse (titre de feuille invalide) qui ont motivé le correctif.
 */
final class ExcelExportServiceTest extends CIUnitTestCase
{
    private function exporter(): ExcelExportService
    {
        return new ExcelExportService(new class extends OracleService {
            public function __construct()
            {
            }
        });
    }

    private function tmpFile(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo_export_test_' . uniqid() . '.xlsx';
    }

    public function testProducesAValidXlsxContainerWithExpectedData(): void
    {
        $file = $this->tmpFile();

        $this->exporter()->exportToExcelAuto($file, [
            ['NOM' => 'Client A', 'VILLE' => 'Douala', 'MONTANT' => 1000],
            ['NOM' => 'Client B', 'VILLE' => 'Yaoundé', 'MONTANT' => 2000],
        ], 'TestRegroup');

        $this->assertFileExists($file);
        $this->assertGreaterThan(0, filesize($file));

        // Un XLSX est un conteneur ZIP : le fichier doit commencer par la
        // signature ZIP locale ("PK\x03\x04"), sinon Excel refuse de l'ouvrir
        // avec "format ou extension non valide".
        $handle = fopen($file, 'rb');
        $magic = fread($handle, 4);
        fclose($handle);
        $this->assertSame("PK\x03\x04", $magic);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true, 'Le fichier doit être une archive ZIP valide.');
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'), 'Structure OOXML minimale absente : [Content_Types].xml');
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'), 'Structure OOXML minimale absente : xl/workbook.xml');
        $zip->close();

        $spreadsheet = IOFactory::load($file);
        $this->assertGreaterThanOrEqual(1, $spreadsheet->getSheetCount());

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('TestRegroup', $sheet->getTitle());
        $this->assertSame('NOM', $sheet->getCell('A1')->getValue());
        $this->assertSame('Client A', $sheet->getCell('A2')->getValue());
        $this->assertSame('Client B', $sheet->getCell('A3')->getValue());
        $this->assertSame(2000, $sheet->getCell('C3')->getValue());
        $this->assertSame(3, $sheet->getHighestRow());

        unlink($file);
    }

    public function testValuesLookingLikeFormulasAreStoredAsSafeText(): void
    {
        $file = $this->tmpFile();

        $this->exporter()->exportToExcelAuto($file, [
            ['NOM' => '=SUM(A1:A10)', 'NOTE' => '@import', 'AUTRE' => '+1', 'MOINS' => '-1'],
        ], 'Test');

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();

        // La valeur doit être conservée telle quelle en TEXTE, jamais évaluée
        // comme une formule Excel (CWE-1236).
        $this->assertSame('=SUM(A1:A10)', $sheet->getCell('A2')->getValue());
        $this->assertSame('s', $sheet->getCell('A2')->getDataType());
        $this->assertSame('@import', $sheet->getCell('B2')->getValue());
        $this->assertSame('s', $sheet->getCell('B2')->getDataType());

        unlink($file);
    }

    public function testInvalidSheetTitleCharactersAreSanitizedInsteadOfThrowing(): void
    {
        $file = $this->tmpFile();

        // / \ : * ? [ ] sont interdits dans un titre de feuille Excel, et un
        // titre de plus de 31 caractères est également invalide.
        $this->exporter()->exportToExcelAuto(
            $file,
            [['NOM' => 'x']],
            'Client/Test:Nom*Invalide[Long]?VraimentTresLong'
        );

        $spreadsheet = IOFactory::load($file);
        $title = $spreadsheet->getActiveSheet()->getTitle();

        $this->assertLessThanOrEqual(31, strlen($title));
        foreach (['/', '\\', ':', '*', '?', '[', ']'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $title);
        }

        unlink($file);
    }

    public function testThrowsOnEmptyDataInsteadOfProducingAnInvalidFile(): void
    {
        $this->expectException(\Exception::class);
        $this->exporter()->exportToExcelAuto($this->tmpFile(), [], 'Test');
    }
}
