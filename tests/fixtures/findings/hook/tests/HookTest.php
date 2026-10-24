<?php

declare(strict_types=1);

namespace Findings\Hook;

use PHPUnit\Framework\TestCase;

final class HookTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $path = getenv('SELECTIVE_HOOK_BOOM');
        $count = is_string($path) && is_file($path) ? (int) file_get_contents($path) : 0;
        if (is_string($path) && $path !== '') {
            file_put_contents($path, (string) ($count + 1));
        }
        if ($count === 0) {
            throw new \RuntimeException('beforeclass boom');
        }
    }

    public function testOne(): void
    {
        $this->assertTrue(true);
    }
}
