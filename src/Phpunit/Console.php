<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

final class Console
{
    private const COLUMNS = 63;

    private const BANNER = <<<'TEXT'
         ____       _           _   _
        / ___|  ___| | ___  ___| |_(_)_   _____
        \___ \ / _ \ |/ _ \/ __| __| \ \ / / _ \
         ___) |  __/ |  __/ (__| |_| |\ V /  __/
        |____/ \___|_|\___|\___|\__|_| \_/ \___|
        ________________________________________
        TEXT;

    private int $marks = 0;

    public function __construct(private readonly mixed $out)
    {
    }

    public function progress(array $result, array $issues): void
    {
        fwrite($this->out, self::mark((string) $result['status'], $issues));
        $this->marks++;
        if ($this->marks % self::COLUMNS === 0) {
            fwrite($this->out, "\n");
        }
    }

    public function message(string $text): void
    {
        if ($this->marks % self::COLUMNS !== 0) {
            fwrite($this->out, "\n");
            $this->marks = 0;
        }
        fwrite($this->out, $text . "\n");
    }

    public function error(string $message): void
    {
        $block = self::BANNER . "\n\nAn error occurred. Please rerun with --debug\nand contact support at https://selective.ci/support\n\n" . $message;
        fwrite($this->out, "\n\e[31m\n" . preg_replace('/^/m', '  ', $block) . "\n\e[0m\n");
    }

    public function summary(array $results, array $issues): void
    {
        $counts = ['Errors' => 0, 'Failures' => 0, 'Skipped' => 0, 'Incomplete' => 0];
        $defects = ['error' => [], 'failure' => []];
        foreach ($results as $id => $result) {
            $flags = $issues[$id] ?? [];
            if ($result['status'] === 'failed') {
                $kind = ($flags['error'] ?? false) ? 'error' : 'failure';
                $counts[$kind === 'error' ? 'Errors' : 'Failures']++;
                $defects[$kind][] = $result;
            } elseif ($result['status'] === 'pending') {
                $counts[($flags['incomplete'] ?? false) ? 'Incomplete' : 'Skipped']++;
            }
        }
        $text = "\n\n" . self::defects('error', $defects['error']) . self::defects('failure', $defects['failure']);
        $total = count($results);
        $counted = array_filter($counts);
        if ($counted === []) {
            fwrite($this->out, $text . "OK ({$total} " . ($total === 1 ? 'test' : 'tests') . ")\n");

            return;
        }
        $headline = match (true) {
            $counts['Errors'] > 0 => 'ERRORS!',
            $counts['Failures'] > 0 => 'FAILURES!',
            default => 'OK, but some tests were skipped or incomplete!',
        };
        $parts = ["Tests: {$total}"];
        foreach ($counted as $label => $count) {
            $parts[] = "{$label}: {$count}";
        }
        fwrite($this->out, $text . $headline . "\n" . implode(', ', $parts) . ".\n");
    }

    private static function mark(string $status, array $issues): string
    {
        return match ($status) {
            'passed' => '.',
            'pending' => ($issues['incomplete'] ?? false) ? 'I' : 'S',
            default => ($issues['error'] ?? false) ? 'E' : 'F',
        };
    }

    private static function defects(string $kind, array $list): string
    {
        $count = count($list);
        if ($count === 0) {
            return '';
        }
        $text = $count === 1 ? "There was 1 {$kind}:\n\n" : "There were {$count} {$kind}s:\n\n";
        foreach ($list as $index => $result) {
            $text .= ($index + 1) . ') ' . $result['full_description'] . "\n";
            $text .= implode("\n", $result['failure_message_lines'] ?? []) . "\n\n";
            $text .= implode("\n", $result['failure_formatted_backtrace'] ?? []) . "\n\n";
        }

        return $text;
    }
}
