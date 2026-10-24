<?php

declare(strict_types=1);

namespace Findings\Deprecation;

use PHPUnit\Framework\TestCase;

final class DeprecationTest extends TestCase
{
    public function testDeprecates(): void
    {
        trigger_error('selective deprecation', E_USER_DEPRECATED);
        $this->assertTrue(true);
    }
}
