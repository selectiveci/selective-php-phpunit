<?php

declare(strict_types=1);

namespace Findings\Phpt;

use PHPUnit\Framework\TestCase;

final class OkTest extends TestCase
{
    public function testOk(): void
    {
        $this->assertTrue(true);
    }
}
