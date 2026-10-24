<?php

declare(strict_types=1);

namespace Findings\Depends;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class DepTest extends TestCase
{
    public function testProducer(): int
    {
        return 42;
    }

    #[Depends('testProducer')]
    public function testConsumer(int $value): void
    {
        $this->assertSame(42, $value);
    }

    #[Depends('testProducer')]
    public function testBroken(int $value): void
    {
        $this->assertSame(43, $value);
    }
}
