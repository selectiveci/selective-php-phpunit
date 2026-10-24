<?php

declare(strict_types=1);

final class MockPeer
{
    public $socket;

    public string $buffer = '';

    public bool $upgraded = false;

    public array $frames = [];

    public bool $closed = false;

    public function __construct($socket)
    {
        $this->socket = $socket;
    }
}

final class Mock
{
    public $listen;

    public $proc;

    public array $pipes = [];

    public array $peers = [];

    public ?MockPeer $current = null;

    public string $clientLog = '';

    public bool $sawConnection = false;

    public string $url = '';

    public function __construct(string $binary, string $selective, string $cwd, array $args, array $env)
    {
        $errno = 0;
        $error = '';
        $listen = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($listen === false) {
            throw new RuntimeException($error);
        }
        stream_set_blocking($listen, false);
        $this->listen = $listen;
        $name = stream_socket_get_name($listen, false);
        $query = http_build_query([
            'api_key' => 'conformance-key',
            'run_id' => 'run-1',
            'run_attempt' => '1',
            'runner_id' => 'runner-1',
            'metadata' => json_encode(['ci' => 'conformance'], JSON_THROW_ON_ERROR),
        ]);
        $this->url = 'ws://' . $name . '/transport/websocket?' . $query;
        $environment = getenv();
        unset($environment['SELECTIVE_API_KEY'], $environment['SELECTIVE_HOST'], $environment['SELECTIVE_RUN_ID']);
        $environment['SELECTIVE_TRANSPORT'] = $binary;
        $environment['SELECTIVE_TRANSPORT_URL'] = $this->url;
        $environment['SELECTIVE_CONFORMANCE_FAST'] = '1';
        foreach ($env as $key => $value) {
            $environment[$key] = $value;
        }
        $command = array_merge([PHP_BINARY, $selective, 'phpunit'], $args);
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, $cwd, $environment);
        if (!is_resource($proc)) {
            throw new RuntimeException('failed to spawn selective');
        }
        $this->proc = $proc;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function send(array $message): void
    {
        $peer = $this->waitCurrent(20.0);
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->writeAll($peer->socket, $this->frame($json));
    }

    public function nextFrame(float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->pump($deadline);
            if ($this->current !== null && $this->current->frames !== []) {
                $text = array_shift($this->current->frames);
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('non-json frame ' . $text);
                }
                if (($decoded['type'] ?? null) === null && $text === 'exit') {
                    throw new RuntimeException('exit was forwarded');
                }

                return $decoded;
            }
        }
        throw new RuntimeException("timed out waiting for a client frame\n" . $this->clientLog);
    }

    public function expectExit(int $code, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->pump($deadline);
            $status = proc_get_status($this->proc);
            if (!$status['running']) {
                $actual = (int) $status['exitcode'];
                if ($actual === -1) {
                    $actual = proc_close($this->proc);
                }
                if ($actual !== $code) {
                    throw new RuntimeException("exit {$actual}, expected {$code}\n" . $this->clientLog);
                }

                return;
            }
            usleep(20000);
        }
        throw new RuntimeException("timed out waiting for the client to exit\n" . $this->clientLog);
    }

    public function close(): void
    {
        foreach ($this->peers as $peer) {
            if (!$peer->closed) {
                @fclose($peer->socket);
            }
        }
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running']) {
                proc_terminate($this->proc);
            }
            proc_close($this->proc);
        }
        if (is_resource($this->listen)) {
            fclose($this->listen);
        }
    }

    private function waitCurrent(float $seconds): MockPeer
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->pump($deadline);
            if ($this->current !== null && !$this->current->closed) {
                return $this->current;
            }
        }
        throw new RuntimeException("no websocket\n" . $this->clientLog);
    }

    private function pump(float $deadline): void
    {
        $this->drainClient();
        $read = [$this->listen];
        foreach ($this->peers as $peer) {
            if (!$peer->closed && is_resource($peer->socket)) {
                $read[] = $peer->socket;
            }
        }
        $write = null;
        $except = null;
        $remaining = max(0.0, $deadline - microtime(true));
        if ($remaining <= 0) {
            return;
        }
        $seconds = (int) floor($remaining);
        $micros = (int) (($remaining - $seconds) * 1000000);
        $selected = @stream_select($read, $write, $except, min($seconds, 0), $remaining < 1 ? $micros : 200000);
        if ($selected === false || $selected === 0) {
            return;
        }
        foreach ($read as $socket) {
            if ($socket === $this->listen) {
                $accepted = @stream_socket_accept($this->listen, 0);
                if ($accepted !== false) {
                    stream_set_blocking($accepted, false);
                    $this->peers[] = new MockPeer($accepted);
                }
                continue;
            }
            foreach ($this->peers as $peer) {
                if ($peer->socket === $socket) {
                    $this->readPeer($peer);
                }
            }
        }
    }

    private function readPeer(MockPeer $peer): void
    {
        $chunk = fread($peer->socket, 65536);
        if ($chunk === false || $chunk === '') {
            if (feof($peer->socket)) {
                $peer->closed = true;
                if ($this->current === $peer) {
                    $this->current = null;
                }
            }

            return;
        }
        $peer->buffer .= $chunk;
        if (!$peer->upgraded) {
            $this->maybeHttp($peer);
        }
        if ($peer->upgraded) {
            $this->readFrames($peer);
        }
    }

    private function maybeHttp(MockPeer $peer): void
    {
        $end = strpos($peer->buffer, "\r\n\r\n");
        if ($end === false) {
            return;
        }
        $header = substr($peer->buffer, 0, $end);
        $peer->buffer = substr($peer->buffer, $end + 4);
        $lines = preg_split('/\r\n/', $header) ?: [];
        $request = $lines[0] ?? '';
        $uri = '';
        if (preg_match('/^GET\s+(\S+)/', $request, $match) === 1) {
            $uri = $match[1];
        }
        if (!$this->sawConnection && str_contains($uri, 'reconnect=true')) {
            throw new RuntimeException('the first connection must not set reconnect=true');
        }
        $metadata = null;
        $parts = parse_url($uri);
        if (is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $metadata = $query['metadata'] ?? null;
        }
        if (!is_string($metadata) || json_decode($metadata, true) === null) {
            throw new RuntimeException('connect metadata is missing or not JSON: ' . $uri);
        }
        $key = '';
        if (preg_match('/sec-websocket-key:\s*(\S+)/i', $header, $match) === 1) {
            $key = $match[1];
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
        $this->writeAll($peer->socket, $response);
        $peer->upgraded = true;
        $this->sawConnection = true;
        $this->current = $peer;
        if ($peer->buffer !== '') {
            $this->readFrames($peer);
        }
    }

    private function readFrames(MockPeer $peer): void
    {
        while (true) {
            $frame = $this->decodeFrame($peer);
            if ($frame === null) {
                return;
            }
            if ($frame === false) {
                continue;
            }
            $peer->frames[] = $frame;
        }
    }

    private function decodeFrame(MockPeer $peer): string|false|null
    {
        $buffer = $peer->buffer;
        if (strlen($buffer) < 2) {
            return null;
        }
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7f;
        $offset = 2;
        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $unpacked = unpack('N2', substr($buffer, 2, 8));
            $length = (int) $unpacked[2];
            $offset = 10;
        }
        $maskLength = $masked ? 4 : 0;
        if (strlen($buffer) < $offset + $maskLength + $length) {
            return null;
        }
        $mask = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLength;
        $payload = substr($buffer, $offset, $length);
        $peer->buffer = substr($buffer, $offset + $length);
        if ($masked) {
            $decoded = '';
            for ($index = 0; $index < $length; $index++) {
                $decoded .= $payload[$index] ^ $mask[$index % 4];
            }
            $payload = $decoded;
        }
        if ($opcode === 0x9) {
            $this->writeAll($peer->socket, chr(0x8A) . chr(strlen($payload)) . $payload);

            return false;
        }
        if ($opcode === 0x8) {
            $peer->closed = true;

            return false;
        }
        if ($opcode !== 0x1) {
            return false;
        }

        return $payload;
    }

    private function frame(string $text): string
    {
        $length = strlen($text);
        $header = chr(0x81);
        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length < 65536) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }

        return $header . $text;
    }

    private function writeAll($socket, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($payload, $offset));
            if ($written === false || $written === 0) {
                $read = null;
                $write = [$socket];
                $except = null;
                @stream_select($read, $write, $except, 1);
                continue;
            }
            $offset += $written;
        }
    }

    private function drainClient(): void
    {
        foreach ([1, 2] as $index) {
            if (!isset($this->pipes[$index]) || !is_resource($this->pipes[$index])) {
                continue;
            }
            $chunk = fread($this->pipes[$index], 8192);
            if (is_string($chunk) && $chunk !== '') {
                $this->clientLog .= $chunk;
            }
        }
    }
}
