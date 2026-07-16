<?php

use App\Factories\MemorySQLFactory;
use CodeIgniter\Test\CIUnitTestCase;

final class MemorySQLFactoryTest extends CIUnitTestCase
{
    public function testBuildReturnsSqlAndBindsForPrepaidWithContrat(): void
    {
        $result = MemorySQLFactory::build('prepaid_contrat', [
            'year' => 2026,
            'cycle' => 10,
            'regroup' => 'PREPAID TEAM',
            'DEBUT' => '01/01/2024',
            'FIN' => '31/01/2024',
        ], 'loading');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $item = $result[0];
        $this->assertArrayHasKey('sql', $item);
        $this->assertArrayHasKey('binds', $item);
        $this->assertIsArray($item['binds']);
        $this->assertSame(
            [
                'year' => 2026,
                'cycle' => 10,
                'regroupName' => 'PREPAID TEAM',
                'dateDebut' => '2024-01-01 00:00:00',
                'dateFin' => '2024-01-31 00:00:00',
            ],
            $item['binds']
        );
        $this->assertStringContainsString(':dateDebut:', $item['sql']);
        $this->assertStringContainsString(':dateFin:', $item['sql']);
        $this->assertStringContainsString(':regroupName:', $item['sql']);
    }

    public function testBuildNormalizesStringResultToSqlBindsArray(): void
    {
        $result = MemorySQLFactory::build('prepaid_contrat', [
            'year' => 2026,
            'cycle' => 10,
            'regroup' => 'PREPAID TEAM',
            'DEBUT' => '01/01/2024',
            'FIN' => '31/01/2024',
        ], 'generation');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        foreach ($result as $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('sql', $item);
            $this->assertArrayHasKey('binds', $item);
            $this->assertSame([], $item['binds']);
        }
    }

    public function testBuildThrowsForUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MemorySQLFactory::build('unknown_type', []);
    }
}
