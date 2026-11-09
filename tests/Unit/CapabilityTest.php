<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Command;

final class CapabilityTest extends TestCase
{
    public function testWithoutPcovTheClientAdvertisesSelect(): void
    {
        $this->assertSame('select', $this->testMap([]));
    }

    public function testWithPcovTheClientAdvertisesRecord(): void
    {
        $this->requireExtension('extension=pcov', 'pcov');

        $this->assertSame('record', $this->testMap(['extension=pcov']));
    }

    public function testTheOldXdebugOptInDoesNotAffectSelect(): void
    {
        $this->assertSame('select', $this->testMap([], ['SELECTIVE_TEST_MAP_XDEBUG' => '1']));
    }

    #[DataProvider('xdebugModes')]
    public function testXdebugAloneNeverMakesTheClientAdvertiseRecord(string $mode): void
    {
        $this->requireExtension('zend_extension=xdebug', 'xdebug');

        $this->assertSame('select', $this->testMap(['zend_extension=xdebug', 'xdebug.mode=' . $mode], ['SELECTIVE_TEST_MAP_XDEBUG' => '1']));
    }

    public static function xdebugModes(): array
    {
        return ['coverage' => ['coverage'], 'develop' => ['develop'], 'off' => ['off']];
    }

    #[DataProvider('disableValues')]
    public function testDisableWinsOverPcov(string $value): void
    {
        $this->requireExtension('extension=pcov', 'pcov');

        $this->assertSame('0', $this->testMap(['extension=pcov'], ['SELECTIVE_TEST_MAP_DISABLE' => $value]));
    }

    public static function disableValues(): array
    {
        return ['1' => ['1'], 'true' => ['true'], 'yes' => ['YES']];
    }

    private function testMap(array $ini, array $env = []): string
    {
        $assignments = 'putenv("SELECTIVE_TEST_MAP_DISABLE");putenv("SELECTIVE_TEST_MAP_XDEBUG");';
        foreach ($env as $name => $value) {
            $assignments .= 'putenv(' . var_export($name . '=' . $value, true) . ');';
        }
        $script = $assignments . 'require ' . var_export(dirname(__DIR__, 2) . '/src/Capability.php', true) . ';'
            . 'echo \Selective\PHPUnit\Capability::testMap();';
        [$code, $stdout] = Command::run($this->php($ini, $script));
        $this->assertSame(0, $code);

        return trim($stdout);
    }

    private function requireExtension(string $directive, string $name): void
    {
        [, $stdout] = Command::run($this->php([$directive], 'echo extension_loaded(' . var_export($name, true) . ') ? "yes" : "no";'));
        if (trim($stdout) !== 'yes') {
            $this->markTestSkipped($name . ' cannot be loaded into a bare PHP here');
        }
    }

    private function php(array $ini, string $script): array
    {
        $command = [PHP_BINARY, '-n'];
        foreach ($ini as $directive) {
            array_push($command, '-d', $directive);
        }

        return array_merge($command, ['-r', $script]);
    }
}
