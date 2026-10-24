<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HookCounterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::bump('SELECTIVE_HOOK_FILE');
    }

    public static function tearDownAfterClass(): void
    {
        self::bump('SELECTIVE_HOOK_AFTER_FILE');
        parent::tearDownAfterClass();
    }

    public function test_one(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_two(): void
    {
        $this->get('/')->assertOk();
    }

    private static function bump(string $variable): void
    {
        $path = getenv($variable);
        if (!is_string($path) || $path === '') {
            return;
        }
        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        file_put_contents($path, (string) ($count + 1));
    }
}
