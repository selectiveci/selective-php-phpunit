<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Url;

final class UrlTest extends TestCase
{
    public function testEveryConnectAdvertisesRemoveSupport(): void
    {
        $url = Url::websocket(['host' => 'ws://localhost:4000', 'api_key' => 'k', 'run_id' => 'r', 'run_attempt' => '1', 'runner_id' => 'r1'], 'select');

        $this->assertSame('true', Url::query($url)['remove_failed']);
        $this->assertSame('true', Url::query(Url::withReconnect($url))['remove_failed']);
        $this->assertSame('true', Url::query(Url::withReconnect($url))['reconnect']);
    }

    public function testAPresetUrlGainsOnlyTheMissingCapabilities(): void
    {
        $this->assertSame('ws://h/t?api_key=k&test_map=select&remove_failed=true', Url::advertise('ws://h/t?api_key=k', 'select'));
        $this->assertSame('ws://h/t?test_map=0&remove_failed=true', Url::advertise('ws://h/t?test_map=0&remove_failed=true', 'select'));
    }
}
