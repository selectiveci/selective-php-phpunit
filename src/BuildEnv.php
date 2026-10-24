<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class BuildEnv
{
    private const REQUIRED = [
        'host' => 'SELECTIVE_HOST',
        'api_key' => 'SELECTIVE_API_KEY',
        'platform' => 'SELECTIVE_PLATFORM',
        'run_id' => 'SELECTIVE_RUN_ID',
        'run_attempt' => 'SELECTIVE_RUN_ATTEMPT',
        'branch' => 'SELECTIVE_BRANCH',
        'sha' => 'SELECTIVE_SHA',
        'git_repo_full_name' => 'SELECTIVE_GIT_REPO',
        'git_provider' => 'SELECTIVE_GIT_PROVIDER',
    ];

    public static function load(string $binary): array
    {
        [$code, $stdout, $stderr] = Command::run([$binary, '--build-env']);
        if ($code !== 0 || $stdout === '') {
            fwrite(STDERR, "Selective transport --build-env failed.\n" . $stderr);
            exit(1);
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Selective transport --build-env did not print JSON.\n");
            exit(1);
        }
        $missing = [];
        foreach (self::REQUIRED as $key => $variable) {
            $value = $decoded[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $missing[] = $variable;
            }
        }
        if ($missing !== []) {
            fwrite(STDERR, 'Missing required environment variables: ' . implode(', ', $missing) . "\n");
            exit(1);
        }
        $host = (string) $decoded['host'];
        if (!preg_match('#^wss?://#', $host)) {
            fwrite(STDERR, "Invalid host: {$host}\n");
            exit(1);
        }

        return $decoded;
    }
}
