<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StopTest extends TestCase
{
    public function testFail(): void
    {
        $this->assertTrue(false);
    }

    public function testPass(): void
    {
        $this->assertTrue(true);
    }
}
