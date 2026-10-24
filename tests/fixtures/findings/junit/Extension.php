<?php

declare(strict_types=1);

use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use PHPUnit\Event\TestRunner\Started;
use PHPUnit\Event\TestRunner\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class LifeExt implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new LifeStarted());
        $facade->registerSubscriber(new LifeFinished());
    }
}

final class LifeStarted implements StartedSubscriber
{
    public function notify(Started $event): void
    {
        $path = getenv('SELECTIVE_LIFE_FILE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, "started\n", FILE_APPEND);
        }
    }
}

final class LifeFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $path = getenv('SELECTIVE_LIFE_FILE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, "finished\n", FILE_APPEND);
        }
    }
}
