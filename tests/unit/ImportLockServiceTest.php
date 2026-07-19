<?php

use App\Services\ImportLockService;
use CodeIgniter\Test\CIUnitTestCase;

final class ImportLockServiceTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-lock-' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testOnlyOneConcurrentImportCanAcquireTheSameTypeLock(): void
    {
        $firstService = new ImportLockService($this->directory, 300);
        $secondService = new ImportLockService($this->directory, 300);

        $firstLock = $firstService->acquire('postpaid_particulier_contrat');

        $this->assertNotNull($firstLock);
        $this->assertNull($secondService->acquire('postpaid_particulier_contrat'));
        $this->assertNotNull($secondService->acquire('prepaid_contrat'));

        $firstLock->release();
        $this->assertNotNull($secondService->acquire('postpaid_particulier_contrat'));
    }

    public function testExpiredLockIsAutomaticallyReplaced(): void
    {
        $type = 'etat_bt_contrat';
        $path = $this->directory . 'import-' . hash('sha256', $type) . '.lock';
        file_put_contents($path, json_encode([
            'type' => $type,
            'token' => 'expired-token',
            'created_at' => time() - 600,
            'expires_at' => time() - 1,
        ]));

        $lock = (new ImportLockService($this->directory, 300))->acquire($type);

        $this->assertNotNull($lock);
        $lock->release();
    }
}
