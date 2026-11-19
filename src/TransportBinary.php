<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class TransportBinary
{
    public const RELEASE = 'v0.3.0';

    public const SHA256 = [
        'transport-Darwin-aarch64.tar.gz' => 'dc5e7fff627041d304ab88aabb21bbdb167a56a21d71c38b2850fc52d6cccd3c',
        'transport-Darwin-x86_64.tar.gz' => '5f491c35d150e6594b4a81070060600af6721a1b558e0cdd2c47712027bb7ed2',
        'transport-Linux-aarch64.tar.gz' => '7ff36a36c78347de37154f6eb461f425b9c7f87b5387d0288162092c6f54090c',
        'transport-Linux-x86_64.tar.gz' => '9a14ceee175a821977aedc69bfcbd9610ff369368a66274bdb64dbb5176bdc8d',
    ];

    private const RELEASES = 'https://github.com/selectiveci/transport/releases/download';

    public static function path(): string
    {
        $explicit = getenv('SELECTIVE_TRANSPORT');
        if (is_string($explicit) && $explicit !== '') {
            if (!is_file($explicit)) {
                throw new \RuntimeException("SELECTIVE_TRANSPORT is {$explicit}, which does not exist.");
            }

            return $explicit;
        }
        $home = (string) getenv('HOME');
        if ($home === '') {
            throw new \RuntimeException('Selective caches its transport under $HOME, which is not set. Set HOME or SELECTIVE_TRANSPORT.');
        }
        $binary = $home . '/.selective/transport/' . self::RELEASE . '/transport';
        if (is_file($binary)) {
            return $binary;
        }
        $asset = self::asset(PHP_OS_FAMILY, php_uname('m'));
        $releases = getenv('SELECTIVE_TRANSPORT_RELEASES') ?: self::RELEASES;
        fwrite(STDERR, 'Selective: downloading transport ' . self::RELEASE . " ({$asset})\n");

        return self::download($releases . '/' . self::RELEASE . '/' . $asset, self::SHA256[$asset], $binary);
    }

    public static function asset(string $os, string $machine): string
    {
        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => $machine,
        };
        $asset = "transport-{$os}-{$arch}.tar.gz";
        if (!isset(self::SHA256[$asset])) {
            throw new \RuntimeException("No transport is pinned for {$os}-{$arch} in release " . self::RELEASE . '. Set SELECTIVE_TRANSPORT to a transport binary.');
        }

        return $asset;
    }

    public static function download(string $url, string $sha256, string $binary): string
    {
        $archive = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'selective-phpunit']]));
        if ($archive === false) {
            throw new \RuntimeException("Selective could not download its transport from {$url}. Set SELECTIVE_TRANSPORT to a transport binary.");
        }
        $actual = hash('sha256', $archive);
        if (!hash_equals($sha256, $actual)) {
            throw new \RuntimeException("The transport downloaded from {$url} has sha256 {$actual}, not the pinned {$sha256}. It was not used.");
        }
        $directory = dirname($binary);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Selective could not create {$directory}.");
        }
        $staging = $directory . '/.staging-' . getmypid();
        mkdir($staging, 0755);
        file_put_contents($staging . '/transport.tar.gz', $archive);
        [$code, , $stderr] = Command::run(['tar', '-xozf', $staging . '/transport.tar.gz', '-C', $staging, 'transport']);
        $unpacked = $code === 0 && is_file($staging . '/transport');
        if ($unpacked) {
            chmod($staging . '/transport', 0755);
            rename($staging . '/transport', $binary);
        }
        foreach (array_diff(scandir($staging) ?: [], ['.', '..']) as $leftover) {
            unlink($staging . '/' . $leftover);
        }
        rmdir($staging);
        if (!$unpacked) {
            throw new \RuntimeException("Selective could not unpack the transport from {$url}: {$stderr}");
        }

        return $binary;
    }
}
