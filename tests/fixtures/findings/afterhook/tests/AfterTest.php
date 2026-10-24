<?php

declare(strict_types=1);

namespace Findings\AfterHook;

use PHPUnit\Framework\TestCase;

final class AfterTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        throw new \RuntimeException('afterclass boom');
    }

    public function testOne(): void
    {
        $this->assertTrue(true);
    }
}
