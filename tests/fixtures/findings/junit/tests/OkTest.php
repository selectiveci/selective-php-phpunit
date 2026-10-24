<?php

declare(strict_types=1);

namespace Findings\Junit;

use PHPUnit\Framework\TestCase;

final class OkTest extends TestCase
{
    public function testOne(): void
    {
        $this->assertTrue(true);
    }

    public function testTwo(): void
    {
        $this->assertTrue(true);
    }
}
