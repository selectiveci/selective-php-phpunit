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

        return extension_loaded('pcov') ? 'record' : 'select';
    }
}
