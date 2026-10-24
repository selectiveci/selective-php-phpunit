<?php

declare(strict_types=1);

namespace Selective\PHPUnit;

final class ShortId
{
    public static function apply(string $id): string
    {
        $invalid = str_contains($id, "\0") || !mb_check_encoding($id, 'UTF-8');
        if (!$invalid && strlen($id) <= 255) {
            return $id;
        }

        $hash = hash('sha256', $id);
        $prefix = str_replace("\0", '', $id);
        if (!mb_check_encoding($prefix, 'UTF-8')) {
            $converted = iconv('UTF-8', 'UTF-8//IGNORE', $prefix);
            $prefix = is_string($converted) ? $converted : '';
        }

        return self::cut($prefix, 190) . '~' . $hash;
    }

    public static function cut(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = substr($value, 0, $maxBytes);
        while ($cut !== '' && !mb_check_encoding($cut, 'UTF-8')) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
