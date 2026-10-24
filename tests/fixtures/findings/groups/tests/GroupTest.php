<?php

declare(strict_types=1);

namespace Findings\Groups;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class GroupTest extends TestCase
{
    #[Group('a')]
    public function testA(): void
    {
        $this->assertTrue(true);
    }

    #[Group('b')]
    public function testB(): void
    {
        $this->assertTrue(false);
    }
}
