<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\ListTestsXml;

final class ListTestsXmlTest extends TestCase
{
    public function testReadsPhpunitTenNames(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<tests>
 <testCaseClass name="RowsTest">
  <testCaseMethod id="RowsTest::testRows#0" name="testRows" groups="default" dataSet="#0"/>
  <testCaseMethod id="RowsTest::testRows#1" name="testRows" groups="default" dataSet="#1"/>
 </testCaseClass>
</tests>
XML;
        $cases = ListTestsXml::parse($xml, 10);

        $this->assertSame(['RowsTest::testRows#0', 'RowsTest::testRows#1'], array_column($cases, 'id'));
        $this->assertSame('RowsTest', $cases[0]['class']);
        $this->assertSame('testRows', $cases[0]['method']);
    }

    public function testReadsPhpunitElevenNames(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<testSuite xmlns="https://xml.phpunit.de/testSuite">
 <tests>
  <testClass name="PassTest" file="/repo/tests/PassTest.php">
   <testMethod id="PassTest::testPass" name="testPass"/>
  </testClass>
  <phpt file="/repo/tests/example.phpt"/>
 </tests>
</testSuite>
XML;
        $cases = ListTestsXml::parse($xml, 11);

        $this->assertSame('PassTest::testPass', $cases[0]['id']);
        $this->assertSame('/repo/tests/PassTest.php', $cases[0]['file']);
        $this->assertSame('/repo/tests/example.phpt', $cases[1]['id']);
    }
}
