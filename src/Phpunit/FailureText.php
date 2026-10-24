<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

use PHPUnit\Event\Code\Throwable as EventThrowable;
use Selective\PHPUnit\Paths;

final class FailureText
{
    public static function lines(?string $message): array
    {
        return preg_split('/\R/', rtrim($message ?? '')) ?: [''];
    }

    public static function frames(?EventThrowable $throwable): array
    {
        $client = [dirname(__DIR__) . '/', dirname(__DIR__, 2) . '/bin/'];
        $frames = [];
        foreach (preg_split('/\R/', $throwable?->stackTrace() ?? '') ?: [] as $row) {
            if (preg_match('/^(.+):(\d+)$/', trim($row), $match) !== 1) {
                continue;
            }
            $real = realpath($match[1]);
            if ($real !== false && (str_starts_with($real, $client[0]) || str_starts_with($real, $client[1]))) {
                continue;
            }
            $relative = Paths::relative($match[1]);
            if (str_starts_with($relative, '/') || str_starts_with($relative, 'vendor/') || str_contains($relative, '/vendor/')) {
                continue;
            }
            $frames[] = $relative . ':' . $match[2];
        }

        return $frames;
    }
}
