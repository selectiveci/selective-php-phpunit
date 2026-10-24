<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Url
{
    public static function websocket(array $env, string $testMap): string
    {
        $metadata = $env;
        unset($metadata['api_key']);
        $query = [
            'api_key' => (string) $env['api_key'],
            'run_id' => (string) $env['run_id'],
            'run_attempt' => (string) $env['run_attempt'],
            'runner_id' => (string) $env['runner_id'],
            'language' => 'php',
            'core_version' => Version::CORE,
            'framework' => 'phpunit',
            'framework_version' => Version::phpunit(),
            'framework_wrapper_version' => Version::WRAPPER,
            'runtime_version' => PHP_VERSION,
            'test_map' => $testMap,
            'test_map_record' => (string) (getenv('SELECTIVE_TEST_MAP_RECORD') ?: ''),
            'remove_failed' => 'true',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        $host = rtrim((string) $env['host'], '/');

        return $host . '/transport/websocket?' . http_build_query($query);
    }

    public static function advertise(string $url, string $testMap): string
    {
        $query = self::query($url);
        $missing = array_diff_key(['test_map' => $testMap, 'remove_failed' => 'true'], $query);
        if ($missing === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($missing);
    }

    public static function withReconnect(string $url): string
    {
        if (preg_match('/(?:\?|&)reconnect=true(?:&|$)/', $url) === 1) {
            return $url;
        }
        $join = str_contains($url, '?') ? '&' : '?';

        return $url . $join . 'reconnect=true';
    }

    public static function query(string $url): array
    {
        $parts = parse_url($url);
        $query = [];
        if (is_array($parts) && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        return $query;
    }
}
