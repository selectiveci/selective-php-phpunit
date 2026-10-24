<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Paths
{
    public static function relative(string $path): string
    {
        $cwd = getcwd() ?: '.';
        $real = realpath($path);
        $root = realpath($cwd);
        if ($real !== false && $root !== false) {
            $prefix = $root . '/';
            if ($real === $root) {
                return '.';
            }
            if (str_starts_with($real, $prefix)) {
                return substr($real, strlen($prefix));
            }
        }
        $prefix = rtrim($cwd, '/') . '/';
        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    public static function commonDirectory(array $files): string
    {
        $dirs = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') {
                continue;
            }
            $dirs[] = $dir;
        }
        if ($dirs === []) {
            return 'tests';
        }
        $prefix = array_shift($dirs);
        foreach ($dirs as $dir) {
            while ($prefix !== '' && $prefix !== '.' && !str_starts_with($dir . '/', $prefix . '/') && $dir !== $prefix) {
                $slash = strrpos($prefix, '/');
                $prefix = $slash === false ? '' : substr($prefix, 0, $slash);
            }
        }
        if ($prefix === '' || $prefix === '.') {
            return 'tests';
        }

        return $prefix;
    }
}
