<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Snapshot
{
    public static function tree(string $binary): ?array
    {
        [$code, $stdout] = Command::run([$binary, '--snapshot']);
        if ($code !== 0 || trim($stdout) === '') {
            return null;
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || !isset($decoded['files'], $decoded['dirty'], $decoded['prefix'])) {
            return null;
        }

        return $decoded;
    }
}
