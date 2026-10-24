<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HookTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $path = getenv('SELECTIVE_HOOK_FILE');
        if (!is_string($path) || $path === '') {
            return;
        }
        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        file_put_contents($path, (string) ($count + 1));
    }

    public static function tearDownAfterClass(): void
    {
        $path = getenv('SELECTIVE_HOOK_AFTER_FILE');
        if (!is_string($path) || $path === '') {
            return;
        }
        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        file_put_contents($path, (string) ($count + 1));
    }

    public function testOne(): void
    {
        $this->assertTrue(true);
    }

    public function testTwo(): void
    {
        $this->assertTrue(true);
    }
}
