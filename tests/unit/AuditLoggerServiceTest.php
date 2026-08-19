<?php

use App\Services\AuditLoggerService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * AuditLoggerService écrit toujours dans writable/audit/<jour>.jsonl (pas de
 * répertoire injectable, contrairement à AuditReaderService) : chaque test
 * enregistre la taille du fichier avant écriture puis le tronque exactement
 * à cette taille après coup (ou le supprime s'il vient d'être créé), pour ne
 * jamais laisser de trace dans le vrai journal d'audit.
 */
final class AuditLoggerServiceTest extends CIUnitTestCase
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

    /** Lit uniquement les lignes ajoutées depuis le snapshot pris dans setUp(). */
    private function readAppendedEntries(): array
    {
        $content = file_get_contents($this->file);
        $appended = substr($content, $this->originalSize);
        $lines = array_filter(explode("\n", trim($appended)));
        return array_map(fn($line) => json_decode($line, true), $lines);
    }

    public function testSuccessfulLoginRecordsTheVerifiedUsername(): void
    {
        session()->set('username', 'ENEO\\jdupont');

        (new AuditLoggerService())->log('LOGIN_SUCCESS', 'SUCCESS', 0.05, service('request'), [
            'category' => 'AUTHENTICATION',
            'severity' => 'SUCCESS',
        ]);

        $entries = $this->readAppendedEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('ENEO\\jdupont', $entries[0]['user']);
    }

    public function testFailedLoginNeverRecordsTheAttemptedUsername(): void
    {
        session()->set('username', 'ENEO\\attacker-guess');

        (new AuditLoggerService())->log('LOGIN_FAILED', 'FAILED', 0.02, service('request'), [
            'category' => 'AUTHENTICATION',
            'severity' => 'WARNING',
            'message'  => 'Échec du bind LDAP (identifiants incorrects)',
        ]);

        $entries = $this->readAppendedEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('anonymous', $entries[0]['user']);
    }

    public function testExplicitUserOverrideIsUsedForSessionExpiredAfterDestroy(): void
    {
        (new AuditLoggerService())->log('SESSION_EXPIRED', 'SUCCESS', 0.0, service('request'), [
            'category' => 'AUTHENTICATION',
            'user'     => 'ENEO\\jdupont',
        ]);

        $entries = $this->readAppendedEntries();
        $this->assertSame('ENEO\\jdupont', $entries[0]['user']);
    }

    public function testSensitiveKeysAreRedactedEvenIfPassedByMistake(): void
    {
        (new AuditLoggerService())->log('MEMORY_GENERATION_FAILED', 'FAILED', 1.2, service('request'), [
            'user_message' => 'Une erreur est survenue.',
            'password'     => 'sup3rSecret!',
            'auth_token'   => 'abc.def.ghi',
            'nested'       => ['api_key' => 'xyz', 'safe' => 'kept'],
        ]);

        $entries = $this->readAppendedEntries();
        $entry = $entries[0];
        $this->assertSame('[REDACTED]', $entry['password']);
        $this->assertSame('[REDACTED]', $entry['auth_token']);
        $this->assertSame('[REDACTED]', $entry['nested']['api_key']);
        $this->assertSame('kept', $entry['nested']['safe']);
        $this->assertStringNotContainsString('sup3rSecret', json_encode($entry));
    }
}
