<?php

declare(strict_types=1);

namespace Findings\ProviderDepends;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class ProviderDepTest extends TestCase
{
    public static function rows(): array
    {
        return ['a' => ['a'], 'b' => ['b']];
    }

    #[DataProvider('rows')]
    public function testProducer(string $row): void
    {
        $this->assertNotSame('', $row);
    }

    #[Depends('testProducer')]
    public function testConsumer(): void
    {
        $this->assertTrue(true);
    }
}
