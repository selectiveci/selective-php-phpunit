<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FailTest extends TestCase
{
    public function testFail(): void
    {
        $this->assertTrue(false);
    }
}
