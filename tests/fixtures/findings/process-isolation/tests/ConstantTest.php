<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConstantTest extends TestCase
{
    public function testDefinesOnce(): void
    {
        define('SELECTIVE_ISOLATED', 1);
        $this->assertSame(1, SELECTIVE_ISOLATED);
    }

    public function testDefinesAgain(): void
    {
        define('SELECTIVE_ISOLATED', 2);
        $this->assertSame(2, SELECTIVE_ISOLATED);
    }
}
