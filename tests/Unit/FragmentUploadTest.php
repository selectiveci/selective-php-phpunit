<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\FragmentUploader;
use Selective\PHPUnit\Signals;

final class FragmentUploadTest extends TestCase
{
    public function testUploadRunsOutsideTheTestProcess(): void
    {
        $addressFile = tempnam(sys_get_temp_dir(), 'addr-');
        $bodyFile = tempnam(sys_get_temp_dir(), 'body-');
        $pidFile = tempnam(sys_get_temp_dir(), 'pid-');
        $this->assertIsString($addressFile);
        $this->assertIsString($bodyFile);
        $this->assertIsString($pidFile);
        $server = <<<'PHP'
<?php
$listen = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listen === false) {
    fwrite(STDERR, $error);
    exit(1);
}
file_put_contents($argv[1], (string) stream_socket_get_name($listen, false));
$client = stream_socket_accept($listen, 10);
if ($client === false) {
    exit(1);
}
$found = [];
foreach (scandir('/proc') ?: [] as $entry) {
    if (!ctype_digit($entry)) {
        continue;
    }
    $cmd = @file_get_contents('/proc/' . $entry . '/cmdline');
    if (is_string($cmd) && str_contains($cmd, 'selective-upload')) {
        $found[] = (int) $entry;
    }
}
file_put_contents($argv[3], json_encode($found));
$buf = '';
while (!str_contains($buf, "\r\n\r\n")) {
    $chunk = fread($client, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $buf .= $chunk;
}
$length = 0;
if (preg_match('/Content-Length:\s*(\d+)/i', $buf, $match)) {
    $length = (int) $match[1];
}
$body = '';
$split = strpos($buf, "\r\n\r\n");
if ($split !== false) {
    $body = substr($buf, $split + 4);
}
while (strlen($body) < $length) {
    $chunk = fread($client, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $body .= $chunk;
}
file_put_contents($argv[2], $buf . $body);
fwrite($client, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
fclose($client);
PHP;
        $script = tempnam(sys_get_temp_dir(), 'srv-');
        $this->assertIsString($script);
        file_put_contents($script, $server);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, $script, $addressFile, $bodyFile, $pidFile], [2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $address = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $address = (string) file_get_contents($addressFile);
            if ($address !== '') {
                break;
            }
            usleep(20000);
        }
        $this->assertNotSame('', $address);
        $ok = FragmentUploader::upload('http://' . $address, 'sk_PROBE_SECRET_KEY', 'run-1', '1', 'runner-1', [
            'version' => 1,
            'files' => ["tests/Ok\xFFTest.php"],
            'tests' => [['id' => 'OkTest::testOk', 'deps' => [0]]],
        ], new Signals());
        $stderr = stream_get_contents($pipes[2]);
        proc_close($proc);
        @unlink($script);
        $this->assertTrue($ok, is_string($stderr) ? $stderr : '');
        $captured = (string) file_get_contents($bodyFile);
        $pids = json_decode((string) file_get_contents($pidFile), true);
        @unlink($addressFile);
        @unlink($bodyFile);
        @unlink($pidFile);
        $this->assertStringContainsString('authorization: sk_PROBE_SECRET_KEY', $captured);
        $this->assertStringContainsString('run_id=run-1', $captured);
        $split = strpos($captured, "\r\n\r\n");
        $this->assertNotFalse($split);
        $decoded = json_decode((string) gzdecode(substr($captured, $split + 4)), true);
        $this->assertIsArray($decoded);
        $this->assertSame("tests/Ok\u{FFFD}Test.php", $decoded['files'][0]);
        $this->assertIsArray($pids);
        $this->assertNotEmpty($pids);
        $this->assertNotContains(getmypid(), $pids);
    }
}
