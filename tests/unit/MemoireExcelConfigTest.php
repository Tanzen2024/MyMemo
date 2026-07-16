<?php

use CodeIgniter\Test\CIUnitTestCase;

final class MemoireExcelConfigTest extends CIUnitTestCase
{
    public function testPrepaidMemoryMergeLabelUsesTheMergeStartCell(): void
    {
        $config = new \Config\MemoirePrepaidExcel();

        $labelHeader = $config->headersAdditionnal[1] ?? null;

        $this->assertIsArray($labelHeader);
        $this->assertSame('F3', $labelHeader['cell']);
        $this->assertSame('F3:H3', $labelHeader['merge']);
    }
}
