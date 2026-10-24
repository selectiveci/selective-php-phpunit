<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreshTest extends TestCase
{
    private int $runs = 0;

    public static function rows(): array
    {
        self::bump('provider');

        return ['only' => [1]];
    }

    #[DataProvider('rows')]
    public function testRetry(int $value): void
    {
        $this->runs++;
        if (self::bump('attempt') === 1) {
            $this->expectException(RuntimeException::class);

            return;
        }
        $this->assertSame([1, 1], [$value, $this->runs]);
    }

    private static function bump(string $key): int
    {
        $path = getenv('SELECTIVE_FRESH_FILE') . '-' . $key;
        $count = ((int) @file_get_contents($path)) + 1;
        file_put_contents($path, (string) $count);

        return $count;
    }
}
