<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Capability
{
    public static function testMap(): string
    {
        $disable = strtolower((string) getenv('SELECTIVE_TEST_MAP_DISABLE'));
        if (in_array($disable, ['1', 'true', 'yes'], true)) {
            return '0';
        }

        return self::canRecord() ? 'record' : 'select';
    }

    public static function canRecord(): bool
    {
        return self::tracerUsable(
            extension_loaded('pcov'),
            self::xdebugModes(),
            getenv('SELECTIVE_TEST_MAP_XDEBUG') === '1'
        );
    }

    public static function tracerUsable(bool $pcov, array $xdebugModes, bool $xdebugOptIn): bool
    {
        return $pcov || ($xdebugOptIn && in_array('coverage', $xdebugModes, true));
    }

    private static function xdebugModes(): array
    {
        if (!extension_loaded('xdebug') || version_compare((string) phpversion('xdebug'), '3.1', '<')) {
            return [];
        }
        $modes = xdebug_info('mode');

        return is_array($modes) ? $modes : [];
    }
}
