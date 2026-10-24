<?php

declare(strict_types=1);

namespace Findings\Isolate;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class IsoClassTest extends TestCase
{
    public function testIso(): void
    {
        $this->assertTrue(class_exists(\PHPUnit\TextUI\Configuration\Registry::class));
    }
}
