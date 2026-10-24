<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StaticsTest extends TestCase
{
    public static ?string $value = null;

    public function testSet(): void
    {
        self::$value = 'leaked';
        $this->assertTrue(true);
    }

    public function testRestored(): void
    {
        $this->assertNull(self::$value);
    }
}
