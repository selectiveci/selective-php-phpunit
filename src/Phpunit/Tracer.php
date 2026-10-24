<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

use Selective\PHPUnit\Paths;

final class Tracer
{
    private ?object $coverage = null;

    private ?string $root = null;

    private bool $ready = false;

    public function available(): bool
    {
        if (extension_loaded('pcov')) {
            return true;
        }

        return extension_loaded('xdebug') && getenv('SELECTIVE_TEST_MAP_XDEBUG') === '1';
    }

    public function start(string $id): void
    {
        $this->ready = false;
        if (!$this->available()) {
            return;
        }
        try {
            if (extension_loaded('pcov')) {
                $current = ini_get('pcov.directory');
                if ($current === false || $current === '') {
                    ini_set('pcov.directory', getcwd() ?: '.');
                }
            }
            $filter = new \SebastianBergmann\CodeCoverage\Filter();
            $driver = extension_loaded('pcov')
                ? new \SebastianBergmann\CodeCoverage\Driver\PcovDriver($filter)
                : new \SebastianBergmann\CodeCoverage\Driver\XdebugDriver($filter);
            $this->coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($driver, $filter);
            $this->coverage->start($id);
            $this->ready = true;
        } catch (\Throwable) {
            $this->coverage = null;
            $this->ready = false;
        }
    }

    public function normalize(string $file): ?string
    {
        $relative = $this->repoRelative($file, $this->repoRoot());
        if ($relative === null || $this->ignored($relative, $this->ignorePrefixes())) {
            return null;
        }

        return $relative;
    }

    public function stop(): array
    {
        if (!$this->ready || $this->coverage === null) {
            return [];
        }
        try {
            $lines = $this->coverage->stop()->lineCoverage();
        } catch (\Throwable) {
            $this->coverage = null;
            $this->ready = false;

            return [];
        }
        $this->coverage = null;
        $this->ready = false;
        $root = $this->repoRoot();
        $ignore = $this->ignorePrefixes();
        $files = [];
        foreach (array_keys($lines) as $file) {
            if (!is_string($file)) {
                continue;
            }
            $relative = $this->repoRelative($file, $root);
            if ($relative === null || $this->ignored($relative, $ignore)) {
                continue;
            }
            $files[$relative] = true;
        }

        return array_keys($files);
    }

    private function repoRoot(): ?string
    {
        if ($this->root !== null) {
            return $this->root;
        }
        [$code, $stdout] = \Selective\PHPUnit\Command::run(['git', 'rev-parse', '--show-toplevel']);
        if ($code !== 0) {
            return null;
        }
        $root = trim($stdout);
        if ($root === '') {
            return null;
        }
        $this->root = $root;

        return $this->root;
    }

    private function repoRelative(string $file, ?string $root): ?string
    {
        if ($root !== null) {
            $root = rtrim($root, '/');
            $real = realpath($file);
            $realRoot = realpath($root);
            if ($real !== false && $realRoot !== false) {
                $prefix = $realRoot . '/';
                if (str_starts_with($real, $prefix)) {
                    return substr($real, strlen($prefix));
                }
            }
        }

        $relative = Paths::relative($file);
        if (str_starts_with($relative, '/')) {
            return null;
        }

        return $relative;
    }

    private function ignorePrefixes(): array
    {
        $prefixes = [];
        $extra = getenv('SELECTIVE_TEST_MAP_IGNORE');
        if (is_string($extra) && $extra !== '') {
            foreach (explode(',', $extra) as $part) {
                $part = trim($part, '/');
                if ($part !== '') {
                    $prefixes[] = $part . '/';
                }
            }
        }

        return $prefixes;
    }

    private function ignored(string $relative, array $prefixes): bool
    {
        $relative = ltrim($relative, '/');
        if (str_contains('/' . $relative, '/vendor/')) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
