<?php
use App\Services\AuditFilterService; use App\Services\AuditReaderService; use CodeIgniter\Test\CIUnitTestCase;
final class AuditReaderServiceTest extends CIUnitTestCase {
 public function testReadsOnlyRequestedDaysAndAppliesFilters(): void { $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'mymemo-audit-'.uniqid().DIRECTORY_SEPARATOR; mkdir($dir); file_put_contents($dir.'2026-07-16.json',json_encode([['date'=>'2026-07-16 10:00:00','user'=>'admin','module'=>'Import Excel','action'=>'Import','status'=>'SUCCESS']])); file_put_contents($dir.'2026-07-15.json',json_encode([['date'=>'2026-07-15 10:00:00','user'=>'other','status'=>'ERROR']])); $f=(new AuditFilterService())->sanitize(['date_from'=>'2026-07-16','date_to'=>'2026-07-16','user'=>'admin']); $rows=(new AuditReaderService($dir))->find($f); $this->assertCount(1,$rows); $this->assertSame('admin',$rows[0]['user']); unlink($dir.'2026-07-16.json'); unlink($dir.'2026-07-15.json'); rmdir($dir); }
}
