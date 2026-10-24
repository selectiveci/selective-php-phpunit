<?php

declare(strict_types=1);

namespace Findings\ClassSkip;

use PHPUnit\Framework\TestCase;

final class SkipTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::markTestSkipped('database not available');
    }

    public function testOne(): void
    {
        $this->assertTrue(false);
    }
}
