<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PendTest extends TestCase
{
    public function testPend(): void
    {
        $this->markTestSkipped('later');
    }
}
