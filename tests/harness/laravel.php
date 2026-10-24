<?php

declare(strict_types=1);

require __DIR__ . '/Mock.php';

$binary = (string) getenv('SELECTIVE_TRANSPORT');
$cwd = (string) ($argv[1] ?? '');
$selective = $cwd . '/vendor/bin/selective';
if (!is_file($selective)) {
    fwrite(STDERR, "usage: laravel.php <laravel app with selective/phpunit installed>\n");
    exit(1);
}

$before = tempnam(sys_get_temp_dir(), 'laravel-before-');
$after = tempnam(sys_get_temp_dir(), 'laravel-after-');
if ($before === false || $after === false) {
    throw new RuntimeException('hook files');
}
file_put_contents($before, '0');
file_put_contents($after, '0');

$mock = new Mock($binary, $selective, $cwd, [], [
    'SELECTIVE_HOOK_FILE' => $before,
    'SELECTIVE_HOOK_AFTER_FILE' => $after,
]);

try {
    $mock->send(['command' => 'connected']);
    $mock->send(['command' => 'test_manifest']);
    $frame = $mock->nextFrame(60);
    if (($frame['type'] ?? '') !== 'test_manifest') {
        throw new RuntimeException('expected test_manifest ' . json_encode($frame));
    }
    $one = null;
    $two = null;
    $example = null;
    foreach ($frame['data']['test_cases'] as $case) {
        $id = (string) $case['id'];
        if (str_ends_with($id, 'HookCounterTest::test_one')) {
            $one = $id;
        }
        if (str_ends_with($id, 'HookCounterTest::test_two')) {
            $two = $id;
        }
        if (str_ends_with($id, 'ExampleTest::test_the_application_returns_a_successful_response')) {
            $example = $id;
        }
    }
    if ($one === null || $two === null || $example === null) {
        throw new RuntimeException('laravel ids missing ' . json_encode($frame['data']['test_cases']));
    }
    $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$one, $two, $example]]);
    expectPassed($mock->nextFrame(60), $one);
    expectPassed($mock->nextFrame(60), $two);
    expectPassed($mock->nextFrame(60), $example);
    $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$one]]);
    expectPassed($mock->nextFrame(60), $one);
    $mock->send(['command' => 'close', 'exit_status' => null]);
    $report = $mock->nextFrame(20);
    if (($report['type'] ?? '') !== 'report_at_finish') {
        throw new RuntimeException('expected report ' . json_encode($report));
    }
    if (!isset($report['data']['seconds_in_before_all_hooks'], $report['data']['seconds_in_after_all_hooks'])) {
        throw new RuntimeException('missing hook timings ' . json_encode($report));
    }
    $mock->expectExit(0, 20);
    $beforeCount = (int) file_get_contents($before);
    $afterCount = (int) file_get_contents($after);
    if ($beforeCount !== 3 || $afterCount !== 3) {
        throw new RuntimeException("hook counts before={$beforeCount} after={$afterCount}");
    }
    fwrite(STDOUT, "ok laravel before={$beforeCount} after={$afterCount}\n");
} finally {
    $mock->close();
    @unlink($before);
    @unlink($after);
}

function expectPassed(array $frame, string $id): void
{
    if (($frame['type'] ?? '') !== 'test_case_result' || ($frame['data']['id'] ?? '') !== $id || ($frame['data']['status'] ?? '') !== 'passed') {
        throw new RuntimeException("expected {$id} passed, got " . json_encode($frame));
    }
}
