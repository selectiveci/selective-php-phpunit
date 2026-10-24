<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Phpunit\ConfigCopy;

final class ConfigCopyTest extends TestCase
{
    public function testForcesEveryStopFlagOffAndKeepsIsolation(): void
    {
        $directory = sys_get_temp_dir() . '/selective-config-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $source = $directory . '/phpunit.xml';
        file_put_contents($source, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit stopOnDefect="true" stopOnError="true" stopOnFailure="true" stopOnWarning="true" stopOnRisky="true" stopOnIncomplete="true" stopOnSkipped="true" stopOnNotice="true" stopOnDeprecation="true" processIsolation="true">
</phpunit>
XML);
        $copy = ConfigCopy::write($source);
        $document = new \DOMDocument();
        $document->load($copy);
        $root = $document->documentElement;
        $this->assertNotNull($root);
        foreach (ConfigCopy::FLAGS as $flag) {
            $this->assertSame('false', $root->getAttribute($flag));
        }
        $this->assertSame('true', $root->getAttribute('processIsolation'));
        $this->assertNotSame($source, $copy);
    }
}
