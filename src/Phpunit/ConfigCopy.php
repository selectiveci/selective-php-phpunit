<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

final class ConfigCopy
{
    public const FLAGS = [
        'stopOnDefect',
        'stopOnError',
        'stopOnFailure',
        'stopOnWarning',
        'stopOnRisky',
        'stopOnIncomplete',
        'stopOnSkipped',
        'stopOnNotice',
        'stopOnDeprecation',
    ];

    public static function write(string $source): string
    {
        $directory = dirname($source);
        $target = $directory . '/phpunit.selective.xml';
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        if ($document->load($source) === false) {
            throw new \RuntimeException('Cannot read ' . $source);
        }
        $root = $document->documentElement;
        if ($root === null || strcasecmp($root->localName, 'phpunit') !== 0) {
            throw new \RuntimeException('phpunit.xml has no phpunit root');
        }
        foreach (self::FLAGS as $flag) {
            $root->setAttribute($flag, 'false');
            if ($root->hasAttribute($flag . 'Threshold')) {
                $root->setAttribute($flag . 'Threshold', '0');
            }
        }
        if ($document->save($target) === false) {
            throw new \RuntimeException('Cannot write ' . $target);
        }

        return $target;
    }
}
