<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PassTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        usleep(500000);
    }

    public function testPass(): void
    {
        $this->assertTrue(true);
    }
}
