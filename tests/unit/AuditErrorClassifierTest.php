<?php

use App\Services\AuditErrorClassifier;
use App\Exceptions\NoDataException;
use App\Exceptions\FileGenerationException;
use CodeIgniter\Test\CIUnitTestCase;

final class AuditErrorClassifierTest extends CIUnitTestCase
{
    private AuditErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AuditErrorClassifier();
    }

    public function testNoDataExceptionIsClassifiedAsNoDataError(): void
    {
        $result = $this->classifier->classify(new NoDataException('Aucune donnée n\'a été trouvée pour les critères sélectionnés (période, cycle ou regroupement).'));

        $this->assertSame('NO_DATA_ERROR', $result['error_type']);
        $this->assertSame('WARNING', $result['severity']);
        $this->assertStringContainsString('Aucune donnée', $result['user_message']);
    }

    public function testFileGenerationExceptionProducesSafeUserMessageWithoutPath(): void
    {
        $result = $this->classifier->classify(new FileGenerationException('Le fichier généré est introuvable ou vide à l\'emplacement attendu : /var/www/writable/exports/secret_internal_path.xlsx'));

        $this->assertSame('FILE_GENERATION_ERROR', $result['error_type']);
        // Le message utilisateur ne doit jamais contenir le chemin serveur.
        $this->assertStringNotContainsString('/var/www', $result['user_message']);
        $this->assertStringNotContainsString('secret_internal_path', $result['user_message']);
        // ... mais le message technique (réservé à l'audit admin) le conserve.
        $this->assertStringContainsString('secret_internal_path', $result['technical_message']);
    }

    public function testExcelImportBusinessMessagesAreRelayedAsIs(): void
    {
        $result = $this->classifier->classify(new RuntimeException('Le fichier Excel est vide (aucune ligne de données après l\'en-tête).'));

        $this->assertSame('VALIDATION_ERROR', $result['error_type']);
        $this->assertSame('Le fichier Excel est vide (aucune ligne de données après l\'en-tête).', $result['user_message']);
    }

    public function testOracleConnectionErrorMapsToRetryLaterMessage(): void
    {
        $result = $this->classifier->classify(new RuntimeException('ORA-12154: TNS:could not resolve the connect identifier specified'));

        $this->assertSame('ORACLE_ERROR', $result['error_type']);
        $this->assertSame('DATABASE', $result['category']);
        $this->assertStringContainsString('momentanément inaccessible', $result['user_message']);
        // Le code ORA- technique doit rester dans le message technique, jamais dans le message utilisateur.
        $this->assertStringNotContainsString('ORA-12154', $result['user_message']);
        $this->assertStringContainsString('ORA-12154', $result['technical_message']);
    }

    public function testUnknownExceptionFallsBackToGenericSafeMessage(): void
    {
        $result = $this->classifier->classify(new \Exception('Undefined array key 3 in /some/internal/path.php on line 42'));

        $this->assertSame('UNKNOWN_ERROR', $result['error_type']);
        $this->assertStringNotContainsString('/some/internal/path.php', $result['user_message']);
    }

    public function testIncidentRefAndCorrelationIdHaveExpectedFormat(): void
    {
        $incident = AuditErrorClassifier::newIncidentRef();
        $correlation = AuditErrorClassifier::newCorrelationId();

        $this->assertMatchesRegularExpression('/^AUD-\d{8}-\d{6}$/', $incident);
        $this->assertMatchesRegularExpression('/^CORR-\d{8}-[0-9A-F]{6}$/', $correlation);
    }
}
