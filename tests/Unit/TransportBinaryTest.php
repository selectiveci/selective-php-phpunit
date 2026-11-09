<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Tests;

use PHPUnit\Framework\TestCase;
use Selective\PHPUnit\Command;
use Selective\PHPUnit\TransportBinary;

final class TransportBinaryTest extends TestCase
{
    public function testEveryReleaseAssetIsMappedAndPlatformsWithoutOneAreToldWhatToDo(): void
    {
        $this->assertSame('transport-Linux-x86_64.tar.gz', TransportBinary::asset('Linux', 'x86_64'));
        $this->assertSame('transport-Linux-x86_64.tar.gz', TransportBinary::asset('Linux', 'amd64'));
        $this->assertSame('transport-Linux-aarch64.tar.gz', TransportBinary::asset('Linux', 'arm64'));
        $this->assertSame('transport-Darwin-aarch64.tar.gz', TransportBinary::asset('Darwin', 'arm64'));
        $this->assertSame('transport-Darwin-x86_64.tar.gz', TransportBinary::asset('Darwin', 'x86_64'));
        $this->expectExceptionMessage('No transport is pinned for Windows-x86_64 in release v0.2.0');
        TransportBinary::asset('Windows', 'AMD64');
    }

    public function testADownloadIsVerifiedThenCached(): void
    {
        $root = sys_get_temp_dir() . '/transport-download-' . bin2hex(random_bytes(4));
        mkdir($root . '/release', 0755, true);
        file_put_contents($root . '/release/transport', "#!/bin/sh\necho transport\n");
        Command::run(['tar', '-czf', $root . '/release/transport.tar.gz', '-C', $root . '/release', 'transport']);
        $url = 'file://' . $root . '/release/transport.tar.gz';
        $sha256 = (string) hash_file('sha256', $root . '/release/transport.tar.gz');

        try {
            TransportBinary::download($url, str_repeat('0', 64), $root . '/cache/v1/transport');
            $this->fail('a checksum mismatch was accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('not the pinned', $error->getMessage());
        }
        $this->assertFileDoesNotExist($root . '/cache/v1/transport');

        $binary = TransportBinary::download($url, $sha256, $root . '/cache/v1/transport');
        $this->assertSame($root . '/cache/v1/transport', $binary);
        $this->assertTrue(is_executable($binary));
        $this->assertSame(['transport'], array_values(array_diff((array) scandir($root . '/cache/v1'), ['.', '..'])));

        Command::run(['rm', '-r', $root]);
    }
}
