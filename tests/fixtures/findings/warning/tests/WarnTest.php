<?php

declare(strict_types=1);

namespace Findings\Warning;

use PHPUnit\Framework\TestCase;

final class WarnTest extends TestCase
{
    public function testWarns(): void
    {
        trigger_error('selective warning', E_USER_WARNING);
        $this->assertTrue(true);
    }
}
