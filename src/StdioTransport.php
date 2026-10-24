<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class StdioTransport
{
    private mixed $process;

    private $stdin;

    private $stdout;

    private $stderr;

    private string $buffer = '';

    private bool $eof = false;

    private bool $shutDown = false;

    private Signals $signals;

    private mixed $log;

    private function __construct(mixed $process, mixed $stdin, mixed $stdout, mixed $stderr, Signals $signals, mixed $log)
    {
        $this->signals = $signals;
        $this->log = $log;
        $this->process = $process;
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
        stream_set_write_buffer($this->stdin, 0);
    }

    public static function open(string $binary, string $url, Signals $signals, mixed $log = null): self
    {
        $env = getenv();
        $env['SELECTIVE_TRANSPORT_URL'] = $url;
        $pipes = [];
        $process = proc_open(
            [$binary, '--stdio'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            throw new ConnectionLost('failed to spawn transport');
        }

        return new self($process, $pipes[0], $pipes[1], $pipes[2], $signals, $log);
    }

    public function writeJson(array $message): void
    {
        $json = json_encode(
            $message,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $this->writeLine($json);
    }

    public function writeLine(string $line): void
    {
        if ($this->shutDown) {
            throw new ConnectionLost('stdin closed');
        }
        $this->pumpStderr();
        $this->record('Sending Response', $line);
        $payload = $line . "\n";
        $written = @fwrite($this->stdin, $payload);
        if ($written === false || $written < strlen($payload)) {
            throw new ConnectionLost('stdin write failed');
        }
        fflush($this->stdin);
    }

    public function readJson(?float $timeout): ?array
    {
        $line = $this->readLine($timeout);

        return $line === null ? null : self::decode($line);
    }

    public function drainJson(): array
    {
        $frames = [];
        while (true) {
            $line = $this->readLine(0.0);
            if ($line === null) {
                break;
            }
            $frames[] = self::decode($line);
        }

        return $frames;
    }

    private static function decode(string $line): array
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Selective received a frame it cannot parse: ' . substr($line, 0, 200));
        }

        return $decoded;
    }

    public function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }
        $this->shutDown = true;
        try {
            @fwrite($this->stdin, "exit\n");
            @fflush($this->stdin);
        } catch (\Throwable) {
        }
        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }
        $deadline = microtime(true) + 1.0;
        if (is_resource($this->process)) {
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            $this->pumpStderr();
            proc_close($this->process);
        }
        $this->process = null;
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }
    }

    private function readLine(?float $timeout): ?string
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        while (true) {
            $this->pumpStderr();
            $position = strpos($this->buffer, "\n");
            if ($position !== false) {
                $line = rtrim(substr($this->buffer, 0, $position), "\r");
                $this->buffer = substr($this->buffer, $position + 1);
                $this->record('Received Command', $line);

                return $line;
            }
            if ($this->signals->pending) {
                throw new Terminated();
            }
            if ($this->eof) {
                if ($this->buffer !== '') {
                    $line = $this->buffer;
                    $this->buffer = '';

                    return rtrim($line, "\r");
                }
                throw new ConnectionLost('stdout closed');
            }
            $remaining = $deadline === null ? 30.0 : ($deadline - microtime(true));
            if ($deadline !== null && $remaining <= 0) {
                return null;
            }
            $read = [$this->stdout];
            $write = null;
            $except = null;
            $seconds = (int) floor(max(0.0, $remaining));
            $micros = (int) ((max(0.0, $remaining) - $seconds) * 1000000);
            $selected = @stream_select($read, $write, $except, $seconds, $micros);
            if ($selected === false) {
                continue;
            }
            if ($selected === 0) {
                if ($deadline === null) {
                    continue;
                }

                return null;
            }
            $chunk = fread($this->stdout, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($this->stdout)) {
                    $this->eof = true;
                }
                continue;
            }
            $this->buffer .= $chunk;
        }
    }

    private function record(string $what, string $line): void
    {
        if ($this->log !== null) {
            fwrite($this->log, '[' . date('c') . "] {$what}: {$line}\n");
        }
    }

    private function pumpStderr(): void
    {
        if (!is_resource($this->stderr)) {
            return;
        }
        while (true) {
            $chunk = fread($this->stderr, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite(STDERR, $chunk);
        }
    }
}
