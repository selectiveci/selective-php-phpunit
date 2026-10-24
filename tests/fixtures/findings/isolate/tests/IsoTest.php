<?php

declare(strict_types=1);

namespace Findings\Isolate;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class IsoTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testIso(): void
    {
        $this->assertTrue(class_exists(\PHPUnit\TextUI\Configuration\Registry::class));
    }
}
