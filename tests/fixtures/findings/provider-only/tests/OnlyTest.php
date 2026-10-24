<?php

declare(strict_types=1);

namespace Findings\ProviderOnly;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnlyTest extends TestCase
{
    public static function blowing(): array
    {
        throw new \RuntimeException('provider blew up');
    }

    #[DataProvider('blowing')]
    public function testBlowing(int $value): void
    {
        $this->assertTrue(true);
    }
}
