<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class Version
{
    public const WRAPPER = '0.1.0';

    public const CORE = '0.1.0';

    public static function phpunit(): string
    {
        return \PHPUnit\Runner\Version::id();
    }

    public static function phpunitMajor(): int
    {
        return \PHPUnit\Runner\Version::majorVersionNumber();
    }
}
