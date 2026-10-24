<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Snapshot;

final class SnapshotTest extends TestCase
{
    public function testALargeStderrDoesNotHangTheSnapshot(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'snap-');
        $this->assertIsString($script);
        file_put_contents($script, "#!/usr/bin/env php\n<?php\nfwrite(STDERR, str_repeat('x', 80000));\nfwrite(STDOUT, json_encode(['files' => [], 'dirty' => false, 'prefix' => '']));\n");
        chmod($script, 0755);
        $started = microtime(true);
        $tree = Snapshot::tree($script);
        $elapsed = microtime(true) - $started;
        @unlink($script);
        $this->assertIsArray($tree);
        $this->assertLessThan(2, $elapsed);
    }
}
