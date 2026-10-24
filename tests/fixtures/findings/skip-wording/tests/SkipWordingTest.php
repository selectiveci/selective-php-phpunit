<?php

declare(strict_types=1);

namespace Findings\SkipWording;

use PHPUnit\Framework\TestCase;

final class SkipWordingTest extends TestCase
{
    public function testSkips(): void
    {
        $this->markTestSkipped('depends on the API to pass');
    }
}
