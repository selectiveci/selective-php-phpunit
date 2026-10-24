<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FlakeTest extends TestCase
{
    public function testFlake(): void
    {
        $path = getenv('SELECTIVE_FLAKE_FILE');
        if (!is_string($path) || $path === '') {
            $path = sys_get_temp_dir() . '/selective-flake-count';
        }
        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        file_put_contents($path, (string) ($count + 1));
        if ($count === 0) {
            $this->fail('flake');
        }
        $this->assertTrue(true);
    }
}
