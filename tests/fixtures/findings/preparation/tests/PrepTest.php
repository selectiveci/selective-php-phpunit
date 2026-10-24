<?php

declare(strict_types=1);

namespace Findings\Preparation;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class PrepTest extends TestCase
{
    #[Before]
    public function boom(): void
    {
        $this->assertTrue(false, 'prep boom');
    }

    public function testBody(): void
    {
        $this->assertTrue(true);
    }
}
