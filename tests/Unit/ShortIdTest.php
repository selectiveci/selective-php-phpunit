<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\ShortId;

final class ShortIdTest extends TestCase
{
    public function testLeavesAShortUtf8IdAlone(): void
    {
        $this->assertSame('PassTest::testPass', ShortId::apply('PassTest::testPass'));
    }

    public function testShortensALongIdOnACharacterBoundary(): void
    {
        $full = str_repeat('é', 200);
        $short = ShortId::apply($full);

        $this->assertLessThanOrEqual(255, strlen($short));
        $this->assertTrue(mb_check_encoding($short, 'UTF-8'));
        $this->assertStringEndsWith('~' . hash('sha256', $full), $short);
        $this->assertSame($short, ShortId::apply($full));
    }

    public function testShortensAnIdThatContainsNul(): void
    {
        $full = "PassTest::testPass\0extra";
        $short = ShortId::apply($full);

        $this->assertStringNotContainsString("\0", $short);
        $this->assertStringEndsWith('~' . hash('sha256', $full), $short);
    }
}
