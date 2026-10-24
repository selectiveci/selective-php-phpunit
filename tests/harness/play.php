<?php

declare(strict_types=1);

require __DIR__ . '/Mock.php';

$root = dirname(__DIR__, 2);
$binary = (string) getenv('SELECTIVE_TRANSPORT');
$selective = $root . '/bin/selective';
$only = $argv[1] ?? 'all';

$failures = [];
if ($only === 'all' || $only === 'unit') {
    passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/vendor/bin/phpunit') . ' --do-not-cache-result', $code);
    if ($code !== 0) {
        $failures[] = 'unit';
    }
}
if ($only === 'all' || $only === 'list') {
    try {
        listXml($root);
        fwrite(STDOUT, "ok list-tests-xml\n");
    } catch (Throwable $error) {
        $failures[] = 'list-tests-xml: ' . $error->getMessage();
    }
}
if ($only === 'all' || $only === 'hooks') {
    try {
        playHooks($binary, $selective, $root . '/tests/fixtures/hooks');
        fwrite(STDOUT, "ok hooks\n");
    } catch (Throwable $error) {
        $failures[] = 'hooks: ' . $error->getMessage();
        fwrite(STDERR, 'FAIL hooks: ' . $error->getMessage() . "\n");
    }
}
if ($only === 'all' || $only === 'stops') {
    try {
        playStops($binary, $selective, $root . '/tests/fixtures/stops');
        fwrite(STDOUT, "ok stops\n");
    } catch (Throwable $error) {
        $failures[] = 'stops: ' . $error->getMessage();
        fwrite(STDERR, 'FAIL stops: ' . $error->getMessage() . "\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "all conformance checks passed\n");
exit(0);

function listXml(string $root): void
{
    $cwd = $root . '/tests/fixtures/provider';
    $out = tempnam(sys_get_temp_dir(), 'sel-xml-');
    if ($out === false) {
        throw new RuntimeException('temp');
    }
    $command = PHP_BINARY . ' ' . escapeshellarg($root . '/vendor/bin/phpunit') . ' --do-not-cache-result --list-tests-xml ' . escapeshellarg($out);
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('phpunit list failed to start');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $xml = (string) file_get_contents($out);
    @unlink($out);
    if ($code !== 0) {
        throw new RuntimeException("phpunit --list-tests-xml exited {$code}\n{$stdout}{$stderr}");
    }
    require_once $root . '/vendor/autoload.php';
    $major = PHPUnit\Runner\Version::majorVersionNumber();
    $parsed = Selective\PHPUnit\Phpunit\ListTestsXml::parse($xml, $major);
    $expected = array_column($parsed, 'id');
    if ($expected === []) {
        throw new RuntimeException("parser found no ids in\n{$xml}");
    }
    $exec = proc_open(
        [PHP_BINARY, $root . '/bin/selective', 'exec', 'phpunit'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($exec)) {
        throw new RuntimeException('selective exec failed to start');
    }
    $listed = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $execCode = proc_close($exec);
    if ($execCode !== 0) {
        throw new RuntimeException("selective exec exited {$execCode}\n{$err}");
    }
    $got = array_values(array_filter(explode("\n", trim((string) $listed)), static fn (string $line): bool => $line !== ''));
    if ($got !== $expected) {
        throw new RuntimeException('list ids ' . json_encode($got) . ' != ' . json_encode($expected));
    }
}

function playHooks(string $binary, string $selective, string $cwd): void
{
    $before = tempnam(sys_get_temp_dir(), 'before-');
    $after = tempnam(sys_get_temp_dir(), 'after-');
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
        $frame = connectedManifest($mock);
        $one = idEndingWith($frame['data']['test_cases'], '::testOne');
        $two = idEndingWith($frame['data']['test_cases'], '::testTwo');
        $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$one, $two]]);
        expectResult($mock->nextFrame(30), $one, 'passed');
        expectResult($mock->nextFrame(30), $two, 'passed');
        $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$one]]);
        expectResult($mock->nextFrame(30), $one, 'passed');
        $mock->send(['command' => 'close', 'exit_status' => null]);
        expectReport($mock->nextFrame(10), 0);
        $mock->expectExit(0, 10);
        $beforeCount = (int) file_get_contents($before);
        $afterCount = (int) file_get_contents($after);
        if ($beforeCount !== 3 || $afterCount !== 3) {
            throw new RuntimeException("hook counts before={$beforeCount} after={$afterCount}");
        }
    } finally {
        $mock->close();
        @unlink($before);
        @unlink($after);
    }
}

function playStops(string $binary, string $selective, string $cwd): void
{
    $flags = [
        'stopOnDefect',
        'stopOnError',
        'stopOnFailure',
        'stopOnWarning',
        'stopOnRisky',
        'stopOnIncomplete',
        'stopOnSkipped',
        'stopOnNotice',
        'stopOnDeprecation',
    ];
    $original = (string) file_get_contents($cwd . '/phpunit.xml');
    try {
        foreach ($flags as $flag) {
            $document = new DOMDocument();
            $document->loadXML($original);
            $document->documentElement?->setAttribute($flag, 'true');
            $document->save($cwd . '/phpunit.xml');
            $mock = new Mock($binary, $selective, $cwd, ['--stop-on-failure'], []);
            try {
                $frame = connectedManifest($mock);
                $fail = idEndingWith($frame['data']['test_cases'], '::testFail');
                $pass = idEndingWith($frame['data']['test_cases'], '::testPass');
                $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$fail]]);
                expectResult($mock->nextFrame(30), $fail, 'failed');
                $mock->send(['command' => 'run_test_cases', 'test_case_ids' => [$pass]]);
                expectResult($mock->nextFrame(30), $pass, 'passed');
                $mock->send(['command' => 'close', 'exit_status' => null]);
                expectReport($mock->nextFrame(10), 0);
                $mock->expectExit(1, 10);
                $copy = $cwd . '/phpunit.selective.xml';
                if (!is_file($copy)) {
                    throw new RuntimeException("{$flag} did not write phpunit.selective.xml");
                }
                $saved = new DOMDocument();
                $saved->load($copy);
                if ($saved->documentElement?->getAttribute($flag) !== 'false') {
                    throw new RuntimeException("{$flag} was not forced off");
                }
            } finally {
                $mock->close();
            }
            fwrite(STDOUT, "ok stop {$flag}\n");
        }
    } finally {
        file_put_contents($cwd . '/phpunit.xml', $original);
    }
}

function connectedManifest(Mock $mock): array
{
    $mock->send(['command' => 'connected']);
    $mock->send(['command' => 'test_manifest']);
    $frame = $mock->nextFrame(30);
    expectManifest($frame);

    return $frame;
}

function idEndingWith(array $cases, string $suffix): string
{
    foreach ($cases as $case) {
        if (str_ends_with($case['id'], $suffix)) {
            return $case['id'];
        }
    }

    throw new RuntimeException('missing test ' . $suffix);
}

function expectManifest(array $frame): void
{
    if (($frame['type'] ?? '') !== 'test_manifest') {
        throw new RuntimeException('expected test_manifest ' . json_encode($frame));
    }
    $cases = $frame['data']['test_cases'] ?? null;
    if (!is_array($cases) || $cases === []) {
        throw new RuntimeException('manifest test_cases');
    }
}

function expectResult(array $frame, string $id, string $status): void
{
    if (($frame['type'] ?? '') !== 'test_case_result') {
        throw new RuntimeException('expected test_case_result ' . json_encode($frame));
    }
    $gotId = (string) ($frame['data']['id'] ?? '');
    $gotStatus = (string) ($frame['data']['status'] ?? '');
    if ($gotId !== $id || $gotStatus !== $status) {
        throw new RuntimeException("expected {$id} {$status}, got {$gotId} {$gotStatus}");
    }
    if ($status === 'failed') {
        $first = (string) ($frame['data']['failure_formatted_backtrace'][0] ?? '');
        if (!str_starts_with($first, 'tests/')) {
            throw new RuntimeException('failure backtrace must start in the repo: ' . $first);
        }
    }
}

function expectReport(array $frame, int $retries): void
{
    if (($frame['type'] ?? '') !== 'report_at_finish') {
        throw new RuntimeException('expected report_at_finish ' . json_encode($frame));
    }
    $got = $frame['data']['connection_retries'] ?? null;
    if ((int) $got !== $retries) {
        throw new RuntimeException('connection_retries ' . json_encode($got) . ', expected ' . $retries);
    }
}
