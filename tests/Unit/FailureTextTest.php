<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Event\Code\Throwable;
use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\FailureText;

final class FailureTextTest extends TestCase
{
    public function testDescriptionLinesDropTheTrailingNewline(): void
    {
        $this->assertSame(['RuntimeException: boom', 'second'], FailureText::lines("RuntimeException: boom\nsecond\n"));
    }

    public function testOnlyRepoRelativeProjectFramesRemain(): void
    {
        $root = (string) getcwd();
        $client = dirname(__DIR__, 2);
        $stack = implode("\n", [
            $root . '/tests/Unit/FailureTextTest.php:21',
            $root . '/vendor/acme/lib/src/Helper.php:8',
            $client . '/src/Phpunit/Engine.php:205',
            $client . '/bin/selective:45',
            '/usr/share/php/Other.php:3',
            $root . '/tests/Unit/OutcomesTest.php:12',
            '',
        ]);

        $frames = FailureText::frames(new Throwable('RuntimeException', 'boom', 'RuntimeException: boom', $stack, null));

        $this->assertSame(['tests/Unit/FailureTextTest.php:21', 'tests/Unit/OutcomesTest.php:12'], $frames);
    }
}
