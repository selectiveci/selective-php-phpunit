<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

use PHPUnit\Event\Event;
use PHPUnit\Event\EventCollection;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Tracer\Tracer;

final class Reports implements Tracer
{
    private Facade $facade;

    private array $before = [];

    private array $attempts = [];

    private array $after = [];

    private ?string $current = null;

    private bool $finished = false;

    public function __construct()
    {
        $this->facade = new Facade();
    }

    public function facade(): Facade
    {
        return $this->facade;
    }

    public function trace(Event $event): void
    {
        if ($this->current !== null) {
            $this->attempts[$this->current][] = $event;
        } elseif ($this->finished) {
            $this->after[] = $event;
        } else {
            $this->before[] = $event;
        }
    }

    public function begin(string $id): void
    {
        unset($this->attempts[$id]);
        $this->attempts[$id] = [];
        $this->current = $id;
    }

    public function end(): void
    {
        $this->current = null;
    }

    public function remove(string $id): void
    {
        unset($this->attempts[$id]);
    }

    public function finishing(): void
    {
        $this->finished = true;
    }

    public function write(): void
    {
        $events = new EventCollection();
        $events->add(...$this->before);
        foreach ($this->attempts as $attempt) {
            $events->add(...$attempt);
        }
        $events->add(...$this->after);
        $this->facade->forward($events);
    }
}
