<?php

declare(strict_types=1);

namespace Findings\Suppressed;

use PHPUnit\Framework\TestCase;

final class SuppressedTest extends TestCase
{
    public function testSuppressedWarning(): void
    {
        @trigger_error('selective warning', E_USER_WARNING);
        $this->assertTrue(true);
    }

    public function testSuppressedDeprecation(): void
    {
        @trigger_error('selective deprecation', E_USER_DEPRECATED);
        $this->assertTrue(true);
    }
}
