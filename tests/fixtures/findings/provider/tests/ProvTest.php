<?php

declare(strict_types=1);

namespace Findings\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProvTest extends TestCase
{
    public static function blowing(): array
    {
        throw new \RuntimeException('provider blew up');
    }

    public static function emptyRows(): array
    {
        return [];
    }

    public static function badShape(): string
    {
        return 'nope';
    }

    #[DataProvider('blowing')]
    public function testBlowing(int $value): void
    {
        $this->assertTrue(true);
    }

    #[DataProvider('emptyRows')]
    public function testEmpty(int $value): void
    {
        $this->assertTrue(true);
    }

    #[DataProvider('badShape')]
    public function testBad(int $value): void
    {
        $this->assertTrue(true);
    }

    public function testHealthy(): void
    {
        $this->assertTrue(true);
    }
}
