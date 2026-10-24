<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class FragmentUploader
{
    public static function upload(string $host, string $apiKey, string $runId, string $runAttempt, string $runnerId, array $fragment, Signals $signals): bool
    {
        $origin = self::httpOrigin($host);
        $url = $origin . '/test_maps/fragments?' . http_build_query([
            'run_id' => $runId,
            'run_attempt' => $runAttempt,
            'runner_id' => $runnerId,
        ]);
        $json = json_encode($fragment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $body = gzencode($json);
        $fast = getenv('SELECTIVE_CONFORMANCE_FAST') === '1';
        $last = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = self::post($url, $apiKey, $body, $signals);
            if ($result === true) {
                return true;
            }
            if ($signals->pending) {
                return false;
            }
            $last = $result;
            if ($attempt < 3 && !$fast) {
                sleep($attempt);
            }
        }
        fwrite(STDERR, "Selective could not upload this runner's test map ({$last}). Its tests will run on the next build rather than be skipped.\n");

        return false;
    }

    private static function httpOrigin(string $host): string
    {
        if (str_starts_with($host, 'wss://')) {
            return 'https://' . substr($host, 6);
        }
        if (str_starts_with($host, 'ws://')) {
            return 'http://' . substr($host, 5);
        }

        return rtrim($host, '/');
    }

    private static function post(string $url, string $apiKey, string $body, Signals $signals): bool|string
    {
        $script = dirname(__DIR__) . '/bin/selective-upload';
        $environment = getenv();
        $environment['SELECTIVE_FRAGMENT_URL'] = $url;
        $environment['SELECTIVE_FRAGMENT_KEY'] = $apiKey;
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($proc)) {
            return 'could not start the upload process';
        }
        fwrite($pipes[0], $body);
        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);
        $error = '';
        $code = null;
        $deadline = microtime(true) + 120;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if ($status === false) {
                break;
            }
            $chunk = stream_get_contents($pipes[2]);
            if (is_string($chunk) && $chunk !== '') {
                $error .= $chunk;
            }
            if (!(bool) ($status['running'] ?? false)) {
                $code = (int) $status['exitcode'];
                break;
            }
            if ($signals->pending) {
                proc_terminate($proc);
                fclose($pipes[2]);
                proc_close($proc);

                return 'terminated';
            }
            usleep(20000);
        }
        fclose($pipes[2]);
        if ($code === null) {
            $code = proc_close($proc);
        } else {
            proc_close($proc);
        }
        if ($signals->pending) {
            return 'terminated';
        }
        if ($code === 0) {
            return true;
        }
        $error = trim($error);

        return $error !== '' ? $error : 'upload process exited ' . $code;
    }
}
