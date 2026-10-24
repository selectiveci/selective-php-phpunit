<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

final class Arguments
{
    public static function strip(array $args): array
    {
        $kept = [];
        $count = count($args);
        for ($index = 0; $index < $count; $index++) {
            $arg = (string) $args[$index];
            if ($arg === '--list-tests' || $arg === '--list-tests-xml') {
                if ($arg === '--list-tests-xml' && isset($args[$index + 1]) && !str_starts_with((string) $args[$index + 1], '-')) {
                    $index++;
                }
                continue;
            }
            if (str_starts_with($arg, '--list-tests-xml=')) {
                continue;
            }
            if (str_starts_with($arg, '--stop-on-')) {
                if (!str_contains($arg, '=') && isset($args[$index + 1]) && preg_match('/^\d+$/', (string) $args[$index + 1])) {
                    $index++;
                }
                continue;
            }
            $kept[] = $arg;
        }

        return $kept;
    }

    public static function withConfiguration(array $args, string $file): array
    {
        $kept = [];
        $count = count($args);
        for ($index = 0; $index < $count; $index++) {
            $arg = (string) $args[$index];
            if ($arg === '--configuration' || $arg === '-c') {
                $index++;
                continue;
            }
            if (str_starts_with($arg, '--configuration=')) {
                continue;
            }
            $kept[] = $arg;
        }
        $kept[] = '--configuration';
        $kept[] = $file;

        return $kept;
    }
}
