<?php

use App\Services\AuditLoggerService;
use App\Services\AuditErrorClassifier;
use App\Services\AuditFilterService;
use App\Services\AuditReaderService;
use App\Exceptions\NoDataException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Scénario d'échec contrôlé de bout en bout (cf. §37 du cahier des charges) :
 * simule un MEMORY_GENERATION_FAILED tel qu'il serait réellement écrit par
 * MemoryController::importAndExport() (mêmes classes, même format), puis
 * vérifie qu'un administrateur consultant le Journal d'audit retrouve bien
 * l'utilisateur, la cause métier, le détail technique et la référence
 * d'incident — et qu'aucune donnée sensible n'apparaît.
 *
 * Écrit dans le vrai writable/audit/<jour>.jsonl (AuditLoggerService n'a pas
 * de répertoire injectable) ; le fichier est restauré à sa taille d'origine
 * dans tearDown() pour ne laisser aucune trace dans le vrai journal.
 */
final class AuditFailureScenarioTest extends CIUnitTestCase
{
    private string $file;
    private int $originalSize = 0;
    private bool $fileExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = WRITEPATH . 'audit' . DIRECTORY_SEPARATOR . date('Y-m-d') . '.jsonl';
        $this->fileExistedBefore = is_file($this->file);
        $this->originalSize = $this->fileExistedBefore ? filesize($this->file) : 0;
    }

    protected function tearDown(): void
    {
        if ($this->fileExistedBefore) {
            $handle = fopen($this->file, 'r+');
            ftruncate($handle, $this->originalSize);
            fclose($handle);
        } elseif (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testMemoryGenerationFailureIsFullyTraceableWithoutExposingInternals(): void
    {
        session()->set('username', 'ENEO\\jdupont');

        // 1) Reproduit exactement ce que fait importAndExport() en cas d'échec :
        //    classification + référence d'incident + écriture de l'événement.
        $classifier = new AuditErrorClassifier();
        $exception = new NoDataException('Aucune donnée n\'a été trouvée pour les critères sélectionnés (période, cycle ou regroupement).');
        $classified = $classifier->classify($exception);
        $incidentRef = AuditErrorClassifier::newIncidentRef();
        $correlationId = AuditErrorClassifier::newCorrelationId();

        (new AuditLoggerService())->log('MEMORY_GENERATION_FAILED', 'FAILED', 8.4, service('request'), [
            'category'          => $classified['category'],
            'severity'          => $classified['severity'],
            'error_type'        => $classified['error_type'],
            'module'            => 'Postpaid',
            'client'            => 'JC_DECAUX',
            'user_message'      => $classified['user_message'],
            'technical_message' => $classified['technical_message'],
            'exception'         => get_class($exception),
            'correlation_id'    => $correlationId,
            'incident_ref'      => $incidentRef,
        ]);

        // 2) Un administrateur consulte le journal du jour : le pipeline
        //    complet lecture + filtre + normalisation doit retrouver l'événement.
        $filters = (new AuditFilterService())->sanitize([
            'date_from'    => date('Y-m-d'),
            'date_to'      => date('Y-m-d'),
            'incident_ref' => $incidentRef,
        ]);
        $rows = (new AuditReaderService())->find($filters);

        $this->assertCount(1, $rows, 'L\'événement FAILED doit être retrouvable par sa référence incident.');
        $entry = $rows[0];

        // A/D : utilisateur AD enregistré
        $this->assertSame('ENEO\\jdupont', $entry['user']);
        // C : événement FAILED bien enregistré, jamais un faux COMPLETED
        $this->assertSame('MEMORY_GENERATION_FAILED', $entry['action']);
        $this->assertSame('FAILED', $entry['status']);
        // E : cause métier lisible
        $this->assertStringContainsString('Aucune donnée', $entry['user_message']);
        // F : détail technique conservé pour l'administrateur
        $this->assertSame('App\\Exceptions\\NoDataException', $entry['exception']);
        $this->assertSame('NO_DATA_ERROR', $entry['error_type']);
        // B : référence incident et corrélation retrouvées
        $this->assertSame($incidentRef, $entry['incident_ref']);
        $this->assertSame($correlationId, $entry['correlation_id']);

        // 3) Retrouvable aussi par correlation_id (parcours complet import → génération).
        $byCorrelation = (new AuditReaderService())->find((new AuditFilterService())->sanitize([
            'date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'correlation_id' => $correlationId,
        ]));
        $this->assertCount(1, $byCorrelation);

        // I : aucune donnée sensible dans l'entrée persistée.
        $dump = json_encode($entry);
        foreach (['password', 'motdepasse', 'secret', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($dump));
        }
    }
}
