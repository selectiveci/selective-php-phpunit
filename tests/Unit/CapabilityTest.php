<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Capability;

final class CapabilityTest extends TestCase
{
    private string|false $disable;

    protected function setUp(): void
    {
        $this->disable = getenv('SELECTIVE_TEST_MAP_DISABLE');
    }

    protected function tearDown(): void
    {
        putenv($this->disable === false ? 'SELECTIVE_TEST_MAP_DISABLE' : 'SELECTIVE_TEST_MAP_DISABLE=' . $this->disable);
    }

    public static function tracers(): array
    {
        return [
            'nothing loaded' => [false, [], false, false],
            'pcov' => [true, [], false, true],
            'pcov wins over the opt-in' => [true, ['develop'], true, true],
            'xdebug coverage without the opt-in' => [false, ['coverage'], false, false],
            'xdebug coverage with the opt-in' => [false, ['coverage'], true, true],
            'xdebug coverage among other modes' => [false, ['develop', 'coverage'], true, true],
            'xdebug without coverage mode' => [false, ['develop'], true, false],
            'xdebug mode off' => [false, [], true, false],
        ];
    }

    #[DataProvider('tracers')]
    public function testATracerIsUsableOnlyWhenItCanActuallyRecord(bool $pcov, array $modes, bool $optIn, bool $expected): void
    {
        $this->assertSame($expected, Capability::tracerUsable($pcov, $modes, $optIn));
    }

    #[DataProvider('disableValues')]
    public function testDisableWinsOverAnyTracer(string $value): void
    {
        putenv('SELECTIVE_TEST_MAP_DISABLE=' . $value);

        $this->assertSame('0', Capability::testMap());
    }

    public static function disableValues(): array
    {
        return ['1' => ['1'], 'true' => ['true'], 'yes' => ['YES']];
    }

    public function testTestMapFollowsTheLoadedTracerWhenNotDisabled(): void
    {
        putenv('SELECTIVE_TEST_MAP_DISABLE');

        $this->assertSame(Capability::canRecord() ? 'record' : 'select', Capability::testMap());
    }
}
