<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Hints
{
    public static function diff(string $targetBranch, int $numCommits, string $testRoot): ?array
    {
        if ($targetBranch === '' || !self::safeRef($targetBranch)) {
            return null;
        }
        $fetch = self::git(['fetch', 'origin', $targetBranch, '--depth=' . $numCommits]);
        if ($fetch['code'] !== 0) {
            fwrite(STDOUT, "Selective was unable to fetch the target branch. This may result in a sub-optimal test order. If the issue persists, please contact support.\n" . $fetch['output']);

            return null;
        }
        $diff = self::git(['diff', 'origin/' . $targetBranch, '--name-only']);
        if ($diff['code'] !== 0) {
            fwrite(STDOUT, "Selective was unable to diff with the target branch. This may result in a sub-optimal test order. If the issue persists, please contact support.\n" . $diff['output']);

            return null;
        }
        $root = rtrim($testRoot, '/') . '/';
        $files = [];
        foreach (preg_split('/\R/', $diff['output']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, $root)) {
                $files[] = $line;
            }
        }

        return $files;
    }

    public static function correlate(array $diffFiles, string $targetBranch, int $numCommits, string $testRoot): ?array
    {
        if ($diffFiles === [] || !self::safeRef($targetBranch)) {
            return null;
        }
        $log = self::git(['log', 'origin/' . $targetBranch, '--no-merges', '--name-only', '--pretty=format:--commit--', '-n', (string) $numCommits]);
        if ($log['code'] !== 0) {
            fwrite(STDOUT, "Selective was unable to correlate the diff to test files. This may result in a sub-optimal test order. If the issue persists, please contact support.\n");

            return null;
        }
        $root = rtrim($testRoot, '/');
        $wanted = [];
        foreach ($diffFiles as $file) {
            if (!self::isTestFile((string) $file, $root)) {
                $wanted[(string) $file] = true;
            }
        }
        $correlated = [];
        $uncorrelated = [];
        foreach (explode('--commit--', $log['output']) as $block) {
            $tests = [];
            $codes = [];
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (self::isTestFile($line, $root)) {
                    $tests[$line] = true;
                }
                if (isset($wanted[$line])) {
                    $codes[$line] = true;
                }
            }
            if ($codes === []) {
                foreach ($tests as $test => $_) {
                    $uncorrelated[$test] = ($uncorrelated[$test] ?? 0) + 1;
                }
                continue;
            }
            foreach ($codes as $code => $_) {
                foreach ($tests as $test => $_) {
                    $correlated[$code][$test] = ($correlated[$code][$test] ?? 0) + 1;
                }
            }
        }

        return [
            'correlated_files' => $correlated,
            'uncorrelated_files' => $uncorrelated,
        ];
    }

    private static function isTestFile(string $file, string $root): bool
    {
        if ($root !== '' && !str_starts_with($file, $root . '/') && $file !== $root) {
            return false;
        }

        return str_ends_with($file, 'Test.php') || str_ends_with($file, '.phpt');
    }

    private static function safeRef(string $ref): bool
    {
        return preg_match('/^[A-Za-z0-9._\\/-]+$/', $ref) === 1 && !str_contains($ref, '..');
    }

    private static function git(array $args): array
    {
        [$code, $stdout, $stderr] = Command::run(array_merge(['git'], $args));

        return ['code' => $code, 'output' => $stdout . $stderr];
    }
}
