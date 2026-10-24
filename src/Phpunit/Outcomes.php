<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

final class Outcomes
{
    public float $beforeClassSeconds = 0.0;

    public float $afterClassSeconds = 0.0;

    public ?string $running = null;

    public array $attempt = [];

    public array $results = [];

    public array $issues = [];

    public array $failOn = [];

    public bool $runnerWarning = false;

    public bool $phpunitError = false;

    public function readFailOn(object $configuration): void
    {
        $all = method_exists($configuration, 'failOnAllIssues') && $configuration->failOnAllIssues();
        $map = [
            'deprecation' => 'Deprecation',
            'phpunit-deprecation' => 'PhpunitDeprecation',
            'phpunit-notice' => 'PhpunitNotice',
            'phpunit-warning' => 'PhpunitWarning',
            'incomplete' => 'Incomplete',
            'notice' => 'Notice',
            'risky' => 'Risky',
            'skipped' => 'Skipped',
            'warning' => 'Warning',
        ];
        foreach ($map as $kind => $suffix) {
            $on = $all;
            $method = 'failOn' . $suffix;
            if (method_exists($configuration, $method) && $configuration->{$method}()) {
                $on = true;
            }
            $off = 'doNotFailOn' . $suffix;
            if (method_exists($configuration, $off) && $configuration->{$off}()) {
                $on = false;
            }
            $this->failOn[$kind] = $on;
        }
    }

    public function beginAttempt(): void
    {
        $this->attempt = [];
    }

    public function running(string $id): void
    {
        $this->running = $id;
    }

    public function note(string $id, string $status, ?string $message = null, ?array $lines = null, ?array $frames = null): void
    {
        $issues = $this->attempt[$id]['issues'] ?? [];
        $current = $this->attempt[$id]['status'] ?? null;
        if ($current === 'failed') {
            return;
        }
        if ($current === 'passed' && $status !== 'failed') {
            return;
        }
        $this->attempt[$id] = [
            'status' => $status,
            'message' => $message,
            'lines' => $lines,
            'frames' => $frames,
            'issues' => $issues,
        ];
    }

    public function issue(string $id, string $kind): void
    {
        $this->attempt[$id]['issues'][$kind] = true;
    }

    public function failRunning(\PHPUnit\Event\Code\Throwable $throwable, bool $error): void
    {
        if ($this->running === null) {
            return;
        }
        $message = $throwable->message();
        $this->note(
            $this->running,
            'failed',
            $message,
            FailureText::lines($throwable->description()),
            FailureText::frames($throwable)
        );
        if ($error) {
            $this->issue($this->running, 'error');
        }
    }

    public function markPhpunitError(string $message): void
    {
        $this->phpunitError = true;
        fwrite(STDERR, $message . "\n");
    }

    public function markRunnerWarning(string $message): void
    {
        $this->runnerWarning = true;
        fwrite(STDERR, $message . "\n");
    }

    public function commit(string $phpunitId, array $case, float $seconds): array
    {
        $file = (string) $case['file_path'];
        $line = (int) $case['line_number'];
        $outcome = $this->attempt[$phpunitId] ?? [
            'message' => 'Selective did not observe an outcome for this test',
            'lines' => null,
            'frames' => [$file . ':' . $line . ': no outcome'],
            'issues' => [],
        ];
        $status = $outcome['status'] ?? 'failed';
        $message = $outcome['message'];
        $result = [
            'id' => $case['id'],
            'description' => $case['description'],
            'full_description' => $case['full_description'],
            'status' => $status,
            'file_path' => $file,
            'line_number' => $line,
            'run_time' => $seconds,
            'pending_message' => $status === 'pending' ? (string) $message : null,
        ];
        if ($status === 'failed') {
            $result['failure_message_lines'] = $outcome['lines'] ?? [(string) $message];
            $result['failure_formatted_backtrace'] = ($outcome['frames'] ?? []) ?: [$file . ':' . $line];
        }
        $id = (string) $case['id'];
        $this->results[$id] = $result;
        $this->issues[$id] = $outcome['issues'] ?? [];

        return $result;
    }

    public function uncollected(string $id): array
    {
        $result = [
            'id' => $id,
            'description' => $id,
            'full_description' => $id,
            'status' => 'failed',
            'file_path' => '',
            'line_number' => 1,
            'run_time' => 0.0,
            'pending_message' => null,
            'failure_message_lines' => ['Selective was asked to run a test that was not collected: ' . $id],
            'failure_formatted_backtrace' => [],
        ];
        $this->results[$id] = $result;
        $this->issues[$id] = [];

        return $result;
    }

    public function remove(string $id): void
    {
        unset($this->results[$id], $this->issues[$id]);
    }

    public function exitStatus(): int
    {
        $code = 0;
        $errored = $this->phpunitError;
        if ($this->runnerWarning && ($this->failOn['phpunit-warning'] ?? false)) {
            $code = 1;
        }
        foreach ($this->results as $id => $result) {
            $status = (string) ($result['status'] ?? '');
            $issues = $this->issues[$id] ?? [];
            if ($status === 'failed') {
                $code = 1;
                $errored = $errored || ($issues['error'] ?? false);
            }
            if ($status === 'pending') {
                if (($issues['skipped'] ?? false) && ($this->failOn['skipped'] ?? false)) {
                    $code = 1;
                }
                if (($issues['incomplete'] ?? false) && ($this->failOn['incomplete'] ?? false)) {
                    $code = 1;
                }
            }
            foreach ($issues as $kind => $on) {
                if ($on && ($this->failOn[$kind] ?? false) && $kind !== 'skipped' && $kind !== 'incomplete') {
                    $code = 1;
                }
            }
        }

        return $errored ? 2 : $code;
    }

    public function hookReport(): array
    {
        return [
            'seconds_in_before_all_hooks' => $this->beforeClassSeconds,
            'seconds_in_after_all_hooks' => $this->afterClassSeconds,
        ];
    }
}
