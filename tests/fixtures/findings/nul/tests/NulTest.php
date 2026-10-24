<?php

declare(strict_types=1);

namespace Findings\Nul;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NulTest extends TestCase
{
    public static function rows(): array
    {
        return [
            "null" => [1],
            "null\0end" => [-1],
        ];
    }

    #[DataProvider('rows')]
    public function testRows(int $value): void
    {
        $this->assertGreaterThan(0, $value);
    }
}
