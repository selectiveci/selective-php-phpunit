<?php

declare(strict_types=1);

namespace Findings\Rows;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowsTest extends TestCase
{
    public static function rows(): array
    {
        $path = getenv('SELECTIVE_PROVIDER_FILE');
        if (is_string($path) && $path !== '') {
            $count = is_file($path) ? (int) file_get_contents($path) : 0;
            file_put_contents($path, (string) ($count + 1));
        }
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows['r' . $i] = [$i];
        }

        return $rows;
    }

    #[DataProvider('rows')]
    public function testRows(int $value): void
    {
        $this->assertGreaterThanOrEqual(0, $value);
    }
}
