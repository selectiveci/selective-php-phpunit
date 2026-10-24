<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Session;

final class SessionTest extends TestCase
{
    public function testDebugAndLogAreSelectivesOwnFlags(): void
    {
        $this->assertSame([['--filter', 'testPass'], true, true], Session::options(['--debug', '--filter', 'testPass', '--log']));
        $this->assertSame([['tests'], false, false], Session::options(['tests']));
    }

    public function testAnErrorPrintsTheSupportBlockOrTheBacktraceUnderDebug(): void
    {
        $plain = $this->session('broken-config', []);
        $this->assertSame(1, $plain['code']);
        $this->assertStringContainsString('An error occurred. Please rerun with --debug', $plain['log']);
        $this->assertStringContainsString('https://selective.ci/support', $plain['log']);
        $this->assertStringNotContainsString('#0 ', $plain['log']);

        $debug = $this->session('broken-config', ['--debug']);
        $this->assertSame(1, $debug['code']);
        $this->assertStringContainsString('RuntimeException', $debug['log']);
        $this->assertStringContainsString('#0 ', $debug['log']);
        $this->assertStringNotContainsString('contact support', $debug['log']);
    }

    public function testLogRecordsEveryCommandAndResponse(): void
    {
        $cwd = dirname(__DIR__) . '/fixtures/suite';
        $ran = $this->session('suite', ['--log', '--filter', 'testPass'], static function (\Mock $mock): void {
            $mock->send(['command' => 'run_test_cases', 'test_case_ids' => ['PassTest::testPass']]);
            $mock->nextFrame(30);
            $mock->send(['command' => 'close', 'exit_status' => null]);
            $mock->nextFrame(10);
        });
        $log = (string) @file_get_contents($cwd . '/log/runner-1.log');
        array_map('unlink', glob($cwd . '/log/*') ?: []);
        @rmdir($cwd . '/log');

        $this->assertSame(0, $ran['code']);
        $this->assertStringContainsString('Received Command: {"command":"run_test_cases"', $log);
        $this->assertStringContainsString('Sending Response: {"type":"test_case_result"', $log);
    }

    private function session(string $fixture, array $args, ?\Closure $after = null): array
    {
        require_once dirname(__DIR__) . '/harness/Mock.php';
        $root = dirname(__DIR__, 2);
        $mock = new \Mock((string) getenv('SELECTIVE_TRANSPORT'), $root . '/bin/selective', $root . '/tests/fixtures/' . $fixture, $args, []);
        try {
            $mock->send(['command' => 'connected']);
            $mock->send(['command' => 'test_manifest']);
            if ($after !== null) {
                $mock->nextFrame(30);
                $after($mock);
            }
            $code = 0;
            try {
                $mock->expectExit(0, 20);
            } catch (\RuntimeException $error) {
                $code = preg_match('/^exit (\d+), expected 0/', $error->getMessage(), $match) === 1 ? (int) $match[1] : -1;
            }

            return ['code' => $code, 'log' => $mock->clientLog . stream_get_contents($mock->pipes[1]) . stream_get_contents($mock->pipes[2])];
        } finally {
            $mock->close();
        }
    }
}
