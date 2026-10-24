<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\AfterLastTestMethodErrored;
use PHPUnit\Event\Test\AfterLastTestMethodFailed;
use PHPUnit\Event\Test\AfterLastTestMethodFinished;
use PHPUnit\Event\Test\BeforeFirstTestMethodErrored;
use PHPUnit\Event\Test\BeforeFirstTestMethodFailed;
use PHPUnit\Event\Test\BeforeFirstTestMethodFinished;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\NoticeTriggered;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpNoticeTriggered;
use PHPUnit\Event\Test\PhpunitDeprecationTriggered;
use PHPUnit\Event\Test\PhpunitErrorTriggered;
use PHPUnit\Event\Test\PhpunitNoticeTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\PhpWarningTriggered;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\TestRunner\WarningTriggered as RunnerWarningTriggered;
use PHPUnit\Event\TestSuite\Skipped as SuiteSkipped;
use PHPUnit\Event\TestSuite\Started as SuiteStarted;
use PHPUnit\Event\Tracer\Tracer;

final class Events implements Tracer
{
    private ?HRTime $suiteStarted = null;

    private ?HRTime $testFinished = null;

    public function __construct(private readonly Outcomes $outcomes)
    {
    }

    public static function register(object $facade, Outcomes $outcomes): void
    {
        $facade->registerTracer(new self($outcomes));
    }

    public function trace(Event $event): void
    {
        match (true) {
            $event instanceof Passed => $this->outcomes->note($event->test()->id(), 'passed'),
            $event instanceof Failed, $event instanceof Errored => $this->failed($event),
            $event instanceof PreparationFailed => $this->preparationFailed($event),
            $event instanceof Skipped => $this->skipped($event),
            $event instanceof MarkedIncomplete => $this->incomplete($event),
            $event instanceof ConsideredRisky => $this->risky($event),
            $event instanceof SuiteSkipped => $this->suiteSkipped($event),
            $event instanceof SuiteStarted => $this->suiteStarted = $event->telemetryInfo()->time(),
            $event instanceof Finished => $this->testFinished = $event->telemetryInfo()->time(),
            $event instanceof BeforeFirstTestMethodFinished => $this->outcomes->beforeClassSeconds += self::since($this->suiteStarted, $event),
            $event instanceof AfterLastTestMethodFinished => $this->outcomes->afterClassSeconds += self::since($this->testFinished, $event),
            $event instanceof BeforeFirstTestMethodErrored,
            $event instanceof AfterLastTestMethodErrored => $this->outcomes->failRunning($event->throwable(), true),
            $event instanceof BeforeFirstTestMethodFailed,
            $event instanceof AfterLastTestMethodFailed => $this->outcomes->failRunning($event->throwable(), false),
            $event instanceof WarningTriggered, $event instanceof PhpWarningTriggered => $this->filtered($event, 'warning'),
            $event instanceof NoticeTriggered, $event instanceof PhpNoticeTriggered => $this->filtered($event, 'notice'),
            $event instanceof DeprecationTriggered, $event instanceof PhpDeprecationTriggered => $this->filtered($event, 'deprecation'),
            $event instanceof PhpunitNoticeTriggered => $this->outcomes->issue($event->test()->id(), 'phpunit-notice'),
            $event instanceof PhpunitErrorTriggered => $this->outcomes->markPhpunitError($event->message()),
            $event instanceof RunnerWarningTriggered => $this->outcomes->markRunnerWarning($event->message()),
            $event instanceof PhpunitWarningTriggered => $this->outcomes->issue($event->test()->id(), 'phpunit-warning'),
            $event instanceof PhpunitDeprecationTriggered => $this->outcomes->issue($event->test()->id(), 'phpunit-deprecation'),
            default => null,
        };
    }

    private function failed(object $event): void
    {
        $throwable = $event->throwable();
        $test = $event->test();
        $this->outcomes->note(
            $test->id(),
            'failed',
            $throwable->message(),
            FailureText::lines($throwable->description()),
            FailureText::frames($throwable)
        );
        if ($event instanceof Errored) {
            $this->outcomes->issue($test->id(), 'error');
        }
    }

    private function preparationFailed(PreparationFailed $event): void
    {
        $test = $event->test();
        $throwable = method_exists($event, 'throwable') ? $event->throwable() : null;
        $message = $throwable !== null ? $throwable->message() : 'preparation failed';
        $this->outcomes->note(
            $test->id(),
            'failed',
            $message,
            FailureText::lines($throwable !== null ? $throwable->description() : $message),
            FailureText::frames($throwable)
        );
    }

    private function skipped(Skipped $event): void
    {
        $message = $event->message();
        $test = $event->test();
        if (preg_match('/^This test depends on ".+" to pass$/', $message) === 1) {
            $this->outcomes->note(
                $test->id(),
                'failed',
                $message,
                FailureText::lines($message),
                []
            );

            return;
        }
        $this->outcomes->note($test->id(), 'pending', $message);
        $this->outcomes->issue($test->id(), 'skipped');
    }

    private function incomplete(MarkedIncomplete $event): void
    {
        $message = $event->throwable()->message();
        $this->outcomes->note($event->test()->id(), 'pending', $message);
        $this->outcomes->issue($event->test()->id(), 'incomplete');
    }

    private function risky(ConsideredRisky $event): void
    {
        $id = $event->test()->id();
        $this->outcomes->issue($id, 'risky');
        if (($this->outcomes->attempt[$id]['status'] ?? null) === null) {
            $this->outcomes->note($id, 'passed', $event->message());
        }
    }

    private function suiteSkipped(SuiteSkipped $event): void
    {
        $message = $event->message();
        foreach ($event->testSuite()->tests()->asArray() as $test) {
            $this->outcomes->note($test->id(), 'pending', $message);
            $this->outcomes->issue($test->id(), 'skipped');
        }
    }

    private static function since(?HRTime $start, Event $event): float
    {
        return $start === null ? 0.0 : $event->telemetryInfo()->time()->duration($start)->asFloat();
    }

    private function filtered(object $event, string $kind): void
    {
        if ($event->ignoredByBaseline() || $event->wasSuppressed()) {
            return;
        }
        $this->outcomes->issue($event->test()->id(), $kind);
    }
}
