<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\Outcomes;

final class OutcomesTest extends TestCase
{
    private Outcomes $outcomes;

    protected function setUp(): void
    {
        $this->outcomes = new Outcomes();
    }

    public function testAnUncollectedIdReportsNoFrame(): void
    {
        $result = $this->outcomes->uncollected('Gone::testGone');

        $this->assertSame(['failed', '', []], [$result['status'], $result['file_path'], $result['failure_formatted_backtrace']]);
    }

    public function testAMissingOutcomeIsFailed(): void
    {
        $case = [
            'id' => 'PassTest::testPass',
            'description' => 'testPass',
            'full_description' => 'PassTest::testPass',
            'file_path' => 'tests/PassTest.php',
            'line_number' => 9,
        ];
        $this->outcomes->beginAttempt();
        $result = $this->outcomes->commit('PassTest::testPass', $case, 0.1);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $this->outcomes->exitStatus());
        $this->assertStringStartsWith('tests/', $result['failure_formatted_backtrace'][0]);
    }

    public function testARetryReplacesTheEarlierResult(): void
    {
        $case = [
            'id' => 'FlakeTest::testFlake',
            'description' => 'testFlake',
            'full_description' => 'FlakeTest::testFlake',
            'file_path' => 'tests/FlakeTest.php',
            'line_number' => 12,
        ];
        $this->outcomes->beginAttempt();
        $this->outcomes->note('FlakeTest::testFlake', 'failed', 'flake', ['flake'], ['tests/FlakeTest.php:12: flake']);
        $this->outcomes->commit('FlakeTest::testFlake', $case, 0.2);
        $this->outcomes->beginAttempt();
        $this->outcomes->note('FlakeTest::testFlake', 'passed');
        $result = $this->outcomes->commit('FlakeTest::testFlake', $case, 0.1);

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $this->outcomes->exitStatus());
    }

    public function testRemoveClearsAFailureAndUnknownIdsAreIgnored(): void
    {
        $case = [
            'id' => 'FailTest::testFail',
            'description' => 'testFail',
            'full_description' => 'FailTest::testFail',
            'file_path' => 'tests/FailTest.php',
            'line_number' => 9,
        ];
        $this->outcomes->beginAttempt();
        $this->outcomes->note('FailTest::testFail', 'failed', 'no', ['no'], ['tests/FailTest.php:9: no']);
        $this->outcomes->commit('FailTest::testFail', $case, 0.1);
        $this->outcomes->remove('missing');
        $this->assertSame(1, $this->outcomes->exitStatus());
        $this->outcomes->remove('FailTest::testFail');
        $this->assertSame(0, $this->outcomes->exitStatus());
    }

    public function testALaterSkipDoesNotOverwriteAPass(): void
    {
        $this->outcomes->beginAttempt();
        $this->outcomes->note('PassTest::testPass', 'passed');
        $this->outcomes->note('PassTest::testPass', 'pending', 'skip');

        $this->assertSame('passed', $this->outcomes->attempt['PassTest::testPass']['status']);
    }
}
