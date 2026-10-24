<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Signals
{
    public bool $pending = false;

    public string $name = 'TERM';

    public ?int $forced = null;

    public function install(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT => 'INT', SIGTERM => 'TERM'] as $signal => $name) {
            pcntl_signal($signal, function () use ($name): void {
                $this->pending = true;
                $this->name = $name;
            });
        }
    }
}
