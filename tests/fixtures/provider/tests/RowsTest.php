<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowsTest extends TestCase
{
    public static function rows(): array
    {
        return [
            'alpha' => [1],
            'beta' => [2],
        ];
    }

    #[DataProvider('rows')]
    public function testRows(int $value): void
    {
        $this->assertGreaterThan(0, $value);
    }
}
