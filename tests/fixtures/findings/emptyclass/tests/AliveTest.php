<?php

declare(strict_types=1);

namespace Findings\EmptyClass;

use PHPUnit\Framework\TestCase;

final class AliveTest extends TestCase
{
    public function testOk(): void
    {
        $this->assertTrue(true);
    }
}
