<?php

declare(strict_types=1);

namespace Findings\RetryProducer;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class RetryProducerTest extends TestCase
{
    public function testProducer(): void
    {
        $path = (string) getenv('SELECTIVE_PRODUCER_FILE');
        $count = (int) file_get_contents($path);
        file_put_contents($path, (string) ($count + 1));
        $this->assertGreaterThan(0, $count);
    }

    #[Depends('testProducer')]
    public function testConsumer(): void
    {
        $this->assertTrue(true);
    }
}
