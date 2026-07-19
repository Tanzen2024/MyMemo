<?php

use App\Filters\ImportManagerFilter;
use App\Services\AuditLoggerService;
use CodeIgniter\Test\CIUnitTestCase;

final class ImportManagerSecurityTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-security-' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($this->directory);
        session()->remove(['id_user', 'username', 'roles']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testFilterRefusesUnauthorizedUserAndAuditsTheAttempt(): void
    {
        session()->set(['username' => 'alice', 'roles' => []]);
        $filter = new ImportManagerFilter(new AuditLoggerService($this->directory));

        $response = $filter->before(service('request'));

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());

        $events = json_decode((string) file_get_contents($this->directory . date('Y-m-d') . '.json'), true);
        $this->assertSame('alice', $events[0]['user']);
        $this->assertSame('REFUSED', $events[0]['status']);
        $this->assertSame('sensitive_operation_authorization', $events[0]['action']);
    }

    public function testFilterAllowsImportManagerRole(): void
    {
        session()->set(['username' => 'manager', 'roles' => [AuditLoggerService::ROLE_IMPORT_MANAGER]]);
        $filter = new ImportManagerFilter(new AuditLoggerService($this->directory));

        $this->assertNull($filter->before(service('request')));
        $this->assertSame([], glob($this->directory . '*.json') ?: []);
    }
}
