<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use RuntimeException;

final class LocalService
{
    public static function origin(mixed $value): array
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || array_diff(array_keys($parts), ['scheme', 'host', 'port'])) {
            throw new RuntimeException('Rehearsal service origins must be plain HTTP on an explicit loopback address.');
        }

        return $parts;
    }
}
