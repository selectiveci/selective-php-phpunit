<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Command
{
    public static function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [1, '', ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $fd = $pipe === $pipes[1] ? 1 : 2;
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    if ($chunk === false || feof($pipe)) {
                        unset($open[$fd]);
                    }
                    continue;
                }
                if ($fd === 1) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
