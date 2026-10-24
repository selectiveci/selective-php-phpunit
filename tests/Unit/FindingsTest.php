<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;

final class FindingsTest extends TestCase
{
    public function testProviderErrorExitsTwoWhenASiblingRemains(): void
    {
        $ran = $this->exec('provider');
        $this->assertSame(2, $ran['code']);
        $this->assertStringContainsString('testHealthy', $ran['stdout']);
        $this->assertStringNotContainsString('testBlowing', $ran['stdout']);
        $this->assertStringContainsString('provider blew up', $ran['stderr']);
    }

    public function testProviderErrorExitsTwoWhenItIsTheOnlyTest(): void
    {
        $ran = $this->exec('provider-only');
        $this->assertSame(2, $ran['code']);
        $this->assertStringContainsString('provider blew up', $ran['stderr']);
    }

    public function testDependsOnADataProviderPasses(): void
    {
        $ran = $this->drive('provider-depends', [['op' => 'run-suffix', 'suffix' => '::testConsumer']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testFailedProducerRunsAgainForTheNextConsumer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'producer-');
        $this->assertIsString($path);
        file_put_contents($path, '0');
        $ran = $this->drive('retry-producer', [
            ['op' => 'run-suffix', 'suffix' => '::testConsumer'],
            ['op' => 'run-suffix', 'suffix' => '::testConsumer'],
        ], ['SELECTIVE_PRODUCER_FILE' => $path]);
        $count = (int) file_get_contents($path);
        @unlink($path);
        $this->assertSame('failed', $ran['json']['results'][0]['status']);
        $this->assertSame('passed', $ran['json']['results'][1]['status']);
        $this->assertSame(0, $ran['json']['exit']);
        $this->assertSame(2, $count);
    }

    public function testARetryRunsAFreshTestCaseAndTheProviderRunsOnce(): void
    {
        $prefix = (string) tempnam(sys_get_temp_dir(), 'fresh-');
        $ran = $this->drive('fresh-retry', [['op' => 'run-all'], ['op' => 'run-all']], ['SELECTIVE_FRESH_FILE' => $prefix]);
        $provider = (string) file_get_contents($prefix . '-provider');
        array_map('unlink', glob($prefix . '*') ?: []);
        $this->assertSame(['failed', 'passed'], array_column($ran['json']['results'], 'status'));
        $this->assertSame('1', $provider);
    }

    public function testBackupStaticPropertiesKeepsOutcomes(): void
    {
        $ran = $this->drive('backup-statics', [['op' => 'run-all']]);
        $this->assertSame(['passed', 'passed'], array_column($ran['json']['results'], 'status'));
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testDependsConsumerRunsItsBody(): void
    {
        $ran = $this->drive('depends', [['op' => 'run-suffix', 'suffix' => '::testConsumer']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testDependsConsumerRunsItsProducerWhenAFilterExcludesIt(): void
    {
        $ran = $this->drive('depends', [['op' => 'run-suffix', 'suffix' => '::testConsumer']], [], ['--filter', 'testConsumer']);
        $this->assertSame(['passed'], array_column($ran['json']['results'], 'status'));
    }

    public function testDependsFailureIsFailedNotPending(): void
    {
        $ran = $this->drive('depends', [['op' => 'run-suffix', 'suffix' => '::testBroken']]);
        $this->assertSame('failed', $ran['json']['results'][0]['status']);
        $this->assertSame(['tests/DepTest.php:26'], $ran['json']['results'][0]['failure_formatted_backtrace']);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testSkipWordingThatMentionsDependsStaysPending(): void
    {
        $ran = $this->drive('skip-wording', [['op' => 'run-all']]);
        $this->assertSame('pending', $ran['json']['results'][0]['status']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testPreparationFailureKeepsTheAssertionMessage(): void
    {
        $ran = $this->drive('preparation', [['op' => 'run-all']]);
        $lines = $ran['json']['results'][0]['failure_message_lines'] ?? [];
        $this->assertSame('failed', $ran['json']['results'][0]['status']);
        if (method_exists(\PHPUnit\Event\Test\PreparationFailed::class, 'throwable')) {
            $this->assertStringContainsString('prep boom', implode("\n", $lines));
        }
    }

    public function testSuppressedIssuesStayGreen(): void
    {
        $ran = $this->drive('suppressed', [['op' => 'run-all']]);
        $this->assertSame(['passed', 'passed'], array_column($ran['json']['results'], 'status'));
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testFailOnWarningFailsTheRun(): void
    {
        $ran = $this->drive('warning', [['op' => 'run-all']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testFailOnNoticeFailsTheRun(): void
    {
        $ran = $this->drive('notice', [['op' => 'run-all']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testFailOnDeprecationFailsTheRun(): void
    {
        $ran = $this->drive('deprecation', [['op' => 'run-all']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testFailOnRiskyFailsTheRun(): void
    {
        $ran = $this->drive('risky', [['op' => 'run-all']]);
        $this->assertSame('passed', $ran['json']['results'][0]['status']);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testRunnerWarningFailsTheRun(): void
    {
        $ran = $this->drive('emptyclass', [['op' => 'run-all']]);
        $this->assertSame(1, $ran['json']['exit']);
        $this->assertStringContainsString('No tests found in class', $ran['stderr']);
    }

    public function testHookErrorIsReplacedByAPassingRetry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hook-boom-');
        $this->assertIsString($path);
        file_put_contents($path, '0');
        $ran = $this->drive('hook', [
            ['op' => 'run-suffix', 'suffix' => '::testOne'],
            ['op' => 'run-suffix', 'suffix' => '::testOne'],
        ], ['SELECTIVE_HOOK_BOOM' => $path]);
        @unlink($path);
        $this->assertSame('failed', $ran['json']['results'][0]['status']);
        $this->assertStringContainsString('beforeclass boom', implode("\n", $ran['json']['results'][0]['failure_message_lines']));
        $this->assertSame('tests/HookTest.php:19', $ran['json']['results'][0]['failure_formatted_backtrace'][0]);
        $this->assertSame('passed', $ran['json']['results'][1]['status']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testRemoveClearsAHookError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hook-boom-');
        $this->assertIsString($path);
        file_put_contents($path, '0');
        $ran = $this->drive('hook', [
            ['op' => 'run-suffix', 'suffix' => '::testOne'],
            ['op' => 'remove-suffix', 'suffix' => '::testOne'],
            ['op' => 'run-suffix', 'suffix' => '::testOne'],
        ], ['SELECTIVE_HOOK_BOOM' => $path]);
        @unlink($path);
        $this->assertSame('passed', $ran['json']['results'][1]['status']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testAfterHookFailureStaysOnTheTest(): void
    {
        $ran = $this->drive('afterhook', [['op' => 'run-all']]);
        $this->assertSame('failed', $ran['json']['results'][0]['status']);
        $this->assertStringContainsString('afterclass boom', implode("\n", $ran['json']['results'][0]['failure_message_lines']));
        $this->assertSame(2, $ran['json']['exit']);
    }

    public function testClassSkipIsPending(): void
    {
        $ran = $this->drive('classskip', [['op' => 'run-all']]);
        $this->assertSame('pending', $ran['json']['results'][0]['status']);
        $this->assertStringContainsString('database not available', (string) $ran['json']['results'][0]['pending_message']);
        $this->assertSame(0, $ran['json']['exit']);
    }

    public function testIsolatedTestPasses(): void
    {
        require_once dirname(__DIR__) . '/harness/Mock.php';
        $root = dirname(__DIR__, 2);
        $binary = getenv('SELECTIVE_TRANSPORT');
        if (!is_string($binary) || $binary === '') {
            $binary = '/tmp/src/transport/target/release/transport';
        }
        $this->assertFileExists($binary);
        $cwd = $root . '/tests/fixtures/findings/isolate';
        $mock = new \Mock($binary, $root . '/bin/selective', $cwd, [], []);
        try {
            $mock->send(['command' => 'connected']);
            $mock->send(['command' => 'test_manifest']);
            $frame = $mock->nextFrame(30);
            $ids = array_column($frame['data']['test_cases'], 'id');
            $this->assertCount(2, $ids);
            $mock->send(['command' => 'run_test_cases', 'test_case_ids' => $ids]);
            $first = $mock->nextFrame(30);
            $second = $mock->nextFrame(30);
            $this->assertSame('passed', $first['data']['status']);
            $this->assertSame('passed', $second['data']['status']);
            $mock->send(['command' => 'close', 'exit_status' => null]);
            $mock->nextFrame(10);
            $mock->expectExit(0, 10);
        } finally {
            $mock->close();
        }
    }

    public function testFailingPhptFailsTheRun(): void
    {
        $ran = $this->drive('phpt', [['op' => 'run-all']]);
        $joined = implode("\n", $ran['json']['manifest']);
        $this->assertStringContainsString('broken.phpt', $joined);
        $failed = false;
        foreach ($ran['json']['results'] as $result) {
            if ($result['status'] === 'failed') {
                $failed = true;
            }
        }
        $this->assertTrue($failed);
        $this->assertSame(1, $ran['json']['exit']);
    }

    public function testGroupNarrowsDiscovery(): void
    {
        $ran = $this->drive('groups', [], [], ['--group', 'a']);
        $this->assertCount(1, $ran['json']['manifest']);
        $this->assertStringEndsWith('::testA', $ran['json']['manifest'][0]);
    }

    public function testFilterNarrowsDiscovery(): void
    {
        $ran = $this->drive('groups', [], [], ['--filter', 'testA']);
        $this->assertCount(1, $ran['json']['manifest']);
        $this->assertStringEndsWith('::testA', $ran['json']['manifest'][0]);
    }

    public function testExcludeGroupNarrowsDiscovery(): void
    {
        $ran = $this->drive('groups', [], [], ['--exclude-group', 'b']);
        $this->assertCount(1, $ran['json']['manifest']);
        $this->assertStringEndsWith('::testA', $ran['json']['manifest'][0]);
    }

    public function testJunitAndRunnerEvents(): void
    {
        $junit = tempnam(sys_get_temp_dir(), 'junit-');
        $life = tempnam(sys_get_temp_dir(), 'life-');
        $this->assertIsString($junit);
        $this->assertIsString($life);
        file_put_contents($life, '');
        $ran = $this->drive('junit', [
            ['op' => 'run-all'],
            ['op' => 'finish'],
        ], ['SELECTIVE_LIFE_FILE' => $life], ['--log-junit', $junit]);
        $xml = (string) file_get_contents($junit);
        $log = (string) file_get_contents($life);
        @unlink($junit);
        @unlink($life);
        $this->assertSame(0, $ran['json']['exit']);
        $this->assertStringContainsString('<testcase', $xml);
        $this->assertSame(2, substr_count($xml, '<testcase'));
        $this->assertStringContainsString('started', $log);
        $this->assertStringContainsString('finished', $log);
    }

    public function testReportsKeepOnlyTheLastAttempt(): void
    {
        $prefix = (string) tempnam(sys_get_temp_dir(), 'reports-');
        $ran = $this->drive('fresh-retry', [
            ['op' => 'run-all'],
            ['op' => 'remove-suffix', 'suffix' => '::testRetry#only'],
            ['op' => 'run-all'],
            ['op' => 'finish'],
        ], ['SELECTIVE_FRESH_FILE' => $prefix], ['--log-junit', $prefix . '-junit', '--log-teamcity', $prefix . '-teamcity']);
        $junit = (string) file_get_contents($prefix . '-junit');
        $teamcity = (string) file_get_contents($prefix . '-teamcity');
        array_map('unlink', glob($prefix . '*') ?: []);
        $this->assertSame(['failed', 'passed'], array_column($ran['json']['results'], 'status'));
        $this->assertSame([1, 0], [substr_count($junit, '<testcase'), substr_count($junit, '<failure')]);
        $this->assertSame([1, 0], [substr_count($teamcity, '##teamcity[testFinished'), substr_count($teamcity, '##teamcity[testFailed')]);
    }

    public function testClassHookTimeIsReportedUnderRubysKeys(): void
    {
        $ran = $this->drive('hook-timing', [['op' => 'run-all'], ['op' => 'run-all']]);
        $hooks = $ran['json']['hooks'];
        $this->assertGreaterThanOrEqual(1.2, $hooks['seconds_in_before_all_hooks']);
        $this->assertLessThan(3.0, $hooks['seconds_in_before_all_hooks']);
        $this->assertGreaterThanOrEqual(0.2, $hooks['seconds_in_after_all_hooks']);
        $this->assertLessThan(1.0, $hooks['seconds_in_after_all_hooks']);
    }

    public function testProcessIsolationRunsEachTestInItsOwnProcess(): void
    {
        $ran = $this->drive('process-isolation', [['op' => 'run-all'], ['op' => 'run-all']]);
        $this->assertSame(['passed', 'passed', 'passed', 'passed'], array_column($ran['json']['results'], 'status'));
    }

    public function testProvidersRunOncePerClass(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'provider-');
        $this->assertIsString($path);
        file_put_contents($path, '0');
        $ran = $this->drive('rows', [['op' => 'run-all']], ['SELECTIVE_PROVIDER_FILE' => $path]);
        $count = (int) file_get_contents($path);
        @unlink($path);
        $this->assertSame(0, $ran['json']['exit']);
        $this->assertLessThanOrEqual(2, $count);
        $this->assertCount(6, $ran['json']['results']);
    }

    public function testNulDatasetNamesStayDistinct(): void
    {
        $ran = $this->drive('nul', [['op' => 'run-all']]);
        $this->assertCount(2, array_unique($ran['json']['manifest']));
        $statuses = array_column($ran['json']['results'], 'status');
        $this->assertContains('passed', $statuses);
        $this->assertContains('failed', $statuses);
        $this->assertSame(1, $ran['json']['exit']);
    }

    private function exec(string $fixture): array
    {
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $root . '/bin/selective', 'exec', 'phpunit'];
        $pipes = [];
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root . '/tests/fixtures/findings/' . $fixture);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function drive(string $fixture, array $steps, array $env = [], array $args = []): array
    {
        $root = dirname(__DIR__, 2);
        $command = array_merge([PHP_BINARY, $root . '/tests/harness/drive.php', $root . '/tests/fixtures/findings/' . $fixture], $args);
        $environment = getenv();
        foreach ($env as $key => $value) {
            $environment[$key] = $value;
        }
        $pipes = [];
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($proc);
        fwrite($pipes[0], json_encode(['steps' => $steps], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';
        $json = json_decode($stdout, true);
        $this->assertIsArray($json, "drive exited {$code}\n{$stderr}\n{$stdout}");

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'json' => $json];
    }
}
