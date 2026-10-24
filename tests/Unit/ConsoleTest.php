<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\Console;

final class ConsoleTest extends TestCase
{
    public function testProgressFailuresAndSummaryReadLikePhpunit(): void
    {
        $results = [
            'A::testFails' => ['status' => 'failed', 'full_description' => 'A::testFails', 'failure_message_lines' => ['Failed asserting that false is true.'], 'failure_formatted_backtrace' => ['tests/ATest.php:12']],
            'A::testErrors' => ['status' => 'failed', 'full_description' => 'A::testErrors', 'failure_message_lines' => ['boom'], 'failure_formatted_backtrace' => ['tests/ATest.php:17']],
            'A::testPasses' => ['status' => 'passed'],
            'A::testSkips' => ['status' => 'pending'],
        ];
        $issues = ['A::testErrors' => ['error' => true], 'A::testSkips' => ['skipped' => true]];
        $out = fopen('php://memory', 'w+');
        $console = new Console($out);
        foreach ($results as $id => $result) {
            $console->progress($result, $issues[$id] ?? []);
        }
        $console->summary($results, $issues);
        rewind($out);

        $this->assertSame(
            "FE.S\n\nThere was 1 error:\n\n1) A::testErrors\nboom\n\ntests/ATest.php:17\n\n"
            . "There was 1 failure:\n\n1) A::testFails\nFailed asserting that false is true.\n\ntests/ATest.php:12\n\n"
            . "ERRORS!\nTests: 4, Errors: 1, Failures: 1, Skipped: 1.\n",
            stream_get_contents($out)
        );
    }

    public function testAServerMessageStartsOnItsOwnLine(): void
    {
        $out = fopen('php://memory', 'w+');
        $console = new Console($out);
        $console->progress(['status' => 'passed'], []);
        $console->message('Selective: notice');
        $console->progress(['status' => 'passed'], []);
        rewind($out);

        $this->assertSame(".\nSelective: notice\n.", stream_get_contents($out));
    }

    public function testAGreenShardSaysOk(): void
    {
        $out = fopen('php://memory', 'w+');
        (new Console($out))->summary(['A::testPasses' => ['status' => 'passed']], []);
        rewind($out);

        $this->assertSame("\n\nOK (1 test)\n", stream_get_contents($out));
    }
}
