<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

use Selective\PHPUnit\Phpunit\Console;
use Selective\PHPUnit\Phpunit\Engine;

final class Session
{
    private Engine $engine;

    private bool $discovered = false;

    private bool $tree = false;

    private bool $record = false;

    private bool $recordWarned = false;

    private int $connectionRetries = 0;

    private array $ids = [];

    private ?string $binary = null;

    private array $args = [];

    private Signals $signals;

    private Console $console;

    private mixed $log = null;

    public function __construct(private readonly bool $debug = false, private readonly bool $logging = false)
    {
        $this->engine = new Engine();
        $this->signals = new Signals();
        $this->console = new Console(STDOUT);
    }

    public static function options(array $args): array
    {
        $rest = array_values(array_filter($args, static fn (mixed $arg): bool => $arg !== '--debug' && $arg !== '--log'));

        return [$rest, in_array('--debug', $args, true), in_array('--log', $args, true)];
    }

    public function run(array $args): never
    {
        try {
            $this->session($args);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    private function session(array $args): never
    {
        $this->args = $args;
        $this->signals->install();
        $binary = TransportBinary::path();
        $this->binary = $binary;
        $base = $this->resolveUrl($binary);
        if ($this->logging) {
            $this->log = self::openLog((string) ($this->ids['runner_id'] ?? 'runner'));
        }
        $failures = 0;
        $reconnect = false;
        while (true) {
            if ($this->signals->pending) {
                $this->finishSignal(null);
            }
            $url = $reconnect ? Url::withReconnect($base) : $base;
            try {
                $transport = StdioTransport::open($binary, $url, $this->signals, $this->log);
            } catch (ConnectionLost $error) {
                $failures++;
                fwrite(STDERR, 'connect failed (' . $failures . '): ' . $error->getMessage() . "\n");
                if ($failures > 10) {
                    exit(1);
                }
                $this->sleep($failures);
                $reconnect = true;
                continue;
            }
            try {
                $code = $this->drive($transport);
                $transport->shutdown();
                exit($code);
            } catch (Terminated) {
                $this->finishSignal($transport);
            } catch (ConnectionLost $error) {
                $transport->shutdown();
                $failures++;
                $this->connectionRetries = $failures;
                fwrite(STDERR, 'connection lost (' . $failures . '): ' . $error->getMessage() . "\n");
                if ($failures > 10) {
                    exit(1);
                }
                $this->sleep($failures);
                $reconnect = true;
            } catch (ReconnectRequested) {
                $transport->shutdown();
                $reconnect = true;
            }
        }
    }

    public function exec(array $args): never
    {
        try {
            $this->listIds($args);
        } catch (\Throwable $error) {
            $this->fail($error);
        }
    }

    private function listIds(array $args): never
    {
        $this->engine->boot($args);
        foreach ($this->engine->manifestCases() as $case) {
            fwrite(STDOUT, $case['id'] . "\n");
        }
        exit($this->engine->exitStatus());
    }

    private function drive(StdioTransport $transport): int
    {
        $queued = [];
        $this->waitConnected($transport, $queued);
        while (true) {
            if ($this->signals->pending) {
                throw new Terminated();
            }
            $command = $queued !== [] ? array_shift($queued) : $transport->readJson(null);
            $name = (string) ($command['command'] ?? '');
            if ($name === 'configure_test_map') {
                $this->configure($command);
                continue;
            }
            if ($name === 'test_manifest') {
                $this->sendManifest($transport);
                continue;
            }
            if ($name === 'run_test_cases') {
                $this->runBatch($transport, $command, $queued);
                continue;
            }
            if ($name === 'remove_failed_test_case_result') {
                $id = (string) ($command['test_case_id'] ?? '');
                if ($id !== '') {
                    $this->engine->remove($id);
                }
                continue;
            }
            if ($name === 'print_notice' || $name === 'print_message') {
                $message = (string) ($command['message'] ?? '');
                if ($message !== '') {
                    $this->console->message($message);
                }
                continue;
            }
            if ($name === 'reconnect') {
                throw new ReconnectRequested();
            }
            if ($name === 'close') {
                return $this->close($transport, $command);
            }
            if ($this->debug) {
                throw new \RuntimeException('Unknown command from Selective: ' . json_encode($command));
            }
        }
    }

    private function fail(\Throwable $error): never
    {
        if ($this->debug) {
            fwrite(STDERR, $error . "\n");
        } else {
            $this->console->error($error->getMessage());
        }
        exit(1);
    }

    private static function openLog(string $runnerId): mixed
    {
        if (!is_dir('log')) {
            mkdir('log', 0777, true);
        }
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|\n\r]+/', '_', $runnerId), '.');

        return fopen('log/' . ($name === '' ? 'runner' : $name) . '.log', 'a');
    }

    private function runBatch(StdioTransport $transport, array $command, array &$queued): void
    {
        $this->ensureDiscovered();
        $ids = $command['test_case_ids'] ?? [];
        if (!is_array($ids)) {
            return;
        }
        foreach ($ids as $id) {
            if ($this->signals->pending) {
                throw new Terminated();
            }
            if (!is_string($id) || $id === '') {
                continue;
            }
            $result = $this->engine->runAssigned($id, $this->record);
            $this->console->progress($result, $this->engine->outcomes()->issues[$result['id']] ?? []);
            try {
                $transport->writeJson([
                    'type' => 'test_case_result',
                    'data' => $result,
                ]);
            } catch (ConnectionLost $error) {
                $this->engine->remove($id);
                throw $error;
            }
            foreach ($transport->drainJson() as $frame) {
                $queued[] = $frame;
            }
        }
    }

    private function sendManifest(StdioTransport $transport): void
    {
        $this->ensureDiscovered();
        $data = [
            'test_cases' => $this->engine->manifestCases(),
        ];
        if ($this->tree && $this->binary !== null && Capability::testMap() !== '0') {
            $tree = Snapshot::tree($this->binary);
            if ($tree !== null) {
                $data['test_map_tree'] = $tree;
            }
        }
        $target = (string) ($this->ids['target_branch'] ?? '');
        if ($target !== '') {
            $files = [];
            foreach ($this->engine->manifestCases() as $case) {
                $files[] = $case['file_path'];
            }
            $root = Paths::commonDirectory($files);
            $modified = Hints::diff($target, $this->numCommits(), $root);
            if ($modified !== null) {
                $data['modified_test_files'] = $modified;
                $correlated = Hints::correlate($modified, $target, $this->numCommits(), $root);
                if ($correlated !== null) {
                    $data['correlated_files'] = $correlated;
                }
            }
        }
        $transport->writeJson([
            'type' => 'test_manifest',
            'data' => $data,
        ]);
    }

    private function ensureDiscovered(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->engine->boot($this->args);
        $this->discovered = true;
    }

    private function configure(array $command): void
    {
        if (($command['record'] ?? false) === true) {
            $this->record = true;
            $xdebug = extension_loaded('xdebug') && getenv('SELECTIVE_TEST_MAP_XDEBUG') === '1';
            if (!extension_loaded('pcov') && !$xdebug && !$this->recordWarned) {
                $this->recordWarned = true;
                $this->console->message('Selective asked this runner to record a test map, but the pcov tracer is not available. Tests will run normally.');
            }
        }
        if (($command['tree'] ?? false) === true && Capability::testMap() !== '0') {
            $this->tree = true;
        }
    }

    private function close(StdioTransport $transport, array $command): int
    {
        $message = $command['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $this->console->message($message);
        }
        $status = $command['exit_status'] ?? null;
        $forced = is_int($status);
        if ($forced) {
            $this->signals->forced = $status;
        } else {
            $this->engine->finish();
            $this->console->summary($this->engine->outcomes()->results, $this->engine->outcomes()->issues);
        }
        $report = ['connection_retries' => $this->connectionRetries];
        foreach ($this->engine->hookReport() as $key => $value) {
            $report[$key] = $value;
        }
        $transport->writeJson([
            'type' => 'report_at_finish',
            'data' => $report,
        ]);
        if ($this->record) {
            $fragment = $this->engine->fragment();
            if ($fragment !== null) {
                FragmentUploader::upload(
                    $this->uploadHost(),
                    (string) ($this->ids['api_key'] ?? ''),
                    (string) ($this->ids['run_id'] ?? ''),
                    (string) ($this->ids['run_attempt'] ?? ''),
                    (string) ($this->ids['runner_id'] ?? ''),
                    $fragment,
                    $this->signals
                );
            }
        }
        if ($this->signals->pending) {
            throw new Terminated();
        }

        return $forced ? (int) $status : $this->engine->exitStatus();
    }

    private function waitConnected(StdioTransport $transport, array &$queued): void
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if ($this->signals->pending) {
                throw new Terminated();
            }
            $remaining = $deadline - microtime(true);
            $frame = $transport->readJson($remaining);
            if ($frame === null) {
                continue;
            }
            if (($frame['command'] ?? '') === 'connected') {
                return;
            }
            $queued[] = $frame;
        }
        fwrite(STDERR, "timed out waiting for connected\n");
        exit(1);
    }

    private function finishSignal(?StdioTransport $transport): never
    {
        if ($transport !== null) {
            try {
                $transport->writeJson([
                    'type' => 'terminating',
                    'data' => ['signal' => $this->signals->name],
                ]);
            } catch (\Throwable) {
            }
        }
        usleep(250000);
        if ($transport !== null) {
            $transport->shutdown();
        }
        $code = $this->signals->forced;
        if ($code === null) {
            $code = $this->engine->exitStatus();
        }
        exit($code);
    }

    private function resolveUrl(string $binary): string
    {
        $preset = getenv('SELECTIVE_TRANSPORT_URL');
        if (is_string($preset) && $preset !== '') {
            $url = Url::advertise($preset, Capability::testMap());
            $this->ids = Url::query($url);
            $this->ids['target_branch'] = (string) (getenv('SELECTIVE_TARGET_BRANCH') ?: '');

            return $url;
        }
        $env = BuildEnv::load($binary);
        if (($env['runner_id'] ?? '') === '') {
            $env['runner_id'] = 'selgen-' . bin2hex(random_bytes(4));
        }
        $this->ids = $env;

        return Url::websocket($env, Capability::testMap());
    }

    private function uploadHost(): string
    {
        $host = (string) ($this->ids['host'] ?? (getenv('SELECTIVE_HOST') ?: ''));
        if ($host !== '') {
            return $host;
        }
        $preset = getenv('SELECTIVE_TRANSPORT_URL');
        if (is_string($preset) && $preset !== '') {
            $parts = parse_url($preset);
            if (is_array($parts) && isset($parts['host'])) {
                $scheme = ($parts['scheme'] ?? 'ws') === 'wss' ? 'https' : 'http';
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';

                return $scheme . '://' . $parts['host'] . $port;
            }
        }

        return '';
    }

    private function numCommits(): int
    {
        $value = $this->ids['num_commits'] ?? 1000;
        $number = (int) $value;

        return $number > 0 ? $number : 1000;
    }

    private function sleep(int $failures): void
    {
        if (getenv('SELECTIVE_CONFORMANCE_FAST') === '1') {
            return;
        }
        if ($this->debug) {
            fwrite(STDOUT, 'Retrying in ' . min($failures, 4) . " seconds...\n");
        }
        sleep(min($failures, 4));
    }
}
