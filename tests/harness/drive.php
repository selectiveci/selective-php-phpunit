<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
require $autoload;
define('PHPUNIT_COMPOSER_INSTALL', realpath($autoload));

use Selective\PHPUnit\Phpunit\Engine;

$fixture = $argv[1] ?? '';
$args = array_slice($argv, 2);
if ($fixture === '' || !is_dir($fixture)) {
    fwrite(STDERR, "usage: drive.php fixture [phpunit args]\n");
    exit(1);
}
chdir($fixture);
$raw = stream_get_contents(STDIN);
$decoded = json_decode(is_string($raw) && $raw !== '' ? $raw : '{"steps":[]}', true);
$steps = is_array($decoded) ? ($decoded['steps'] ?? []) : [];
drive($steps, $args);

function drive(array $steps, array $args): void
{
    $engine = new Engine();
    $engine->boot($args);
    $results = [];
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $op = (string) ($step['op'] ?? '');
        if ($op === 'run-all' || $op === 'run-suffix') {
            $suffix = (string) ($step['suffix'] ?? '');
            foreach ($engine->manifestCases() as $case) {
                if ($op === 'run-suffix' && !str_ends_with($case['id'], $suffix)) {
                    continue;
                }
                $results[] = $engine->runAssigned($case['id'], false);
            }
            continue;
        }
        if ($op === 'remove-suffix') {
            $suffix = (string) ($step['suffix'] ?? '');
            foreach ($engine->manifestCases() as $case) {
                if (str_ends_with($case['id'], $suffix)) {
                    $engine->remove($case['id']);
                }
            }
            continue;
        }
        if ($op === 'finish') {
            $engine->finish();
        }
    }
    fwrite(STDOUT, json_encode([
        'manifest' => array_column($engine->manifestCases(), 'id'),
        'results' => $results,
        'exit' => $engine->exitStatus(),
        'hooks' => $engine->hookReport(),
    ], JSON_THROW_ON_ERROR));
}
