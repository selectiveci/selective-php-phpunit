<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\Arguments;

final class ArgumentsTest extends TestCase
{
    public function testStripsStopFlagsAndKeepsIsolation(): void
    {
        $kept = Arguments::strip([
            '--colors',
            '--stop-on-failure',
            '--stop-on-defect',
            '1',
            '--stop-on-warning=1',
            '--process-isolation',
            '--filter',
            'testPass',
            'tests/PassTest.php',
        ]);

        $this->assertSame(['--colors', '--process-isolation', '--filter', 'testPass', 'tests/PassTest.php'], $kept);
    }

    public function testReplacesConfiguration(): void
    {
        $kept = Arguments::withConfiguration(['-c', 'phpunit.xml', '--filter', 'a'], '/tmp/phpunit.selective.xml');

        $this->assertSame(['--filter', 'a', '--configuration', '/tmp/phpunit.selective.xml'], $kept);
    }
}
