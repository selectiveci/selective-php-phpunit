<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;

final class TimingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        usleep(300000);
    }

    #[BeforeClass]
    public static function second(): void
    {
        usleep(300000);
    }

    public static function tearDownAfterClass(): void
    {
        usleep(100000);
    }

    public function testOnly(): void
    {
        $this->assertTrue(true);
    }
}
