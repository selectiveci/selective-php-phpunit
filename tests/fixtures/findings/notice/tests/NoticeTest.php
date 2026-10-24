<?php

declare(strict_types=1);

namespace Findings\Notice;

use PHPUnit\Framework\TestCase;

final class NoticeTest extends TestCase
{
    public function testNotices(): void
    {
        trigger_error('selective notice', E_USER_NOTICE);
        $this->assertTrue(true);
    }
}
