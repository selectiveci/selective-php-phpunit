<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class TransportBinary
{
    public const RELEASE = 'v0.2.0';

    public const SHA256 = [
        'transport-Darwin-aarch64.tar.gz' => '7dcaec969378a181a62749673b9c0b658be17bf16a8880f5effbd8e03a6c4a17',
        'transport-Darwin-x86_64.tar.gz' => 'ccb9f795086d5584ff605cff8aefc534a82cde3154a3231f7f9396cde27bf7d4',
        'transport-Linux-aarch64.tar.gz' => 'cf54fbf596b8a708fbc64e031f98737f076b8c32c371a4506c8141c49bca84be',
        'transport-Linux-x86_64.tar.gz' => '87b2d6847ed0efa44c73ec16526b85134bf6f2e3759d99f925682eaf1b27d937',
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
