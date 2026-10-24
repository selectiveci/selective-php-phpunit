<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Signals;
use Selective\PHPUnit\StdioTransport;

final class StdioTransportTest extends TestCase
{
    public function testWholeFloatsReachTheTransportAsJsonFloats(): void
    {
        $frames = (string) tempnam(sys_get_temp_dir(), 'frames-');
        $binary = (string) tempnam(sys_get_temp_dir(), 'transport-');
        file_put_contents($binary, "#!/bin/sh\ncat > " . escapeshellarg($frames) . "\n");
        chmod($binary, 0755);

        $transport = StdioTransport::open($binary, 'ws://localhost/unused', new Signals());
        $transport->writeJson(['type' => 'test_case_result', 'data' => ['line_number' => 9, 'run_time' => 0.0, 'seconds' => 2.0]]);
        $transport->shutdown();

        $this->assertSame(
            '{"type":"test_case_result","data":{"line_number":9,"run_time":0.0,"seconds":2.0}}',
            strtok((string) file_get_contents($frames), "\n")
        );
        unlink($frames);
        unlink($binary);
    }

    public function testAFrameThatIsNotJsonStopsTheSession(): void
    {
        $binary = (string) tempnam(sys_get_temp_dir(), 'transport-');
        file_put_contents($binary, "#!/bin/sh\necho 'this is not json'\nsleep 5\n");
        chmod($binary, 0755);
        $transport = StdioTransport::open($binary, 'ws://localhost/unused', new Signals());

        try {
            $transport->readJson(5.0);
            $this->fail('an unparseable frame was accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot parse: this is not json', $error->getMessage());
        } finally {
            $transport->shutdown();
            unlink($binary);
        }
    }

    public function testTheTransportsOwnErrorsReachStderr(): void
    {
        $binary = (string) tempnam(sys_get_temp_dir(), 'transport-');
        file_put_contents($binary, "#!/bin/sh\necho 'Connection refused (os error 111)' >&2\n");
        chmod($binary, 0755);
        $script = '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$t = Selective\\PHPUnit\\StdioTransport::open(' . var_export($binary, true) . ', "ws://localhost/unused", new Selective\\PHPUnit\\Signals());'
            . 'try { $t->readJson(5.0); } catch (Selective\\PHPUnit\\ConnectionLost) {} $t->shutdown();';
        $file = (string) tempnam(sys_get_temp_dir(), 'stderr-');
        file_put_contents($file, $script);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        unlink($file);
        unlink($binary);

        $this->assertStringContainsString('Connection refused (os error 111)', $stderr);
    }
}
