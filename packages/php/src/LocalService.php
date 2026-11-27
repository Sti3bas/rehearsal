<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use RuntimeException;

final class LocalService
{
    public static function isOrigin(mixed $value): bool
    {
        $parts = is_string($value) ? parse_url($value) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            && array_diff(array_keys($parts), ['scheme', 'host', 'port']) === [];
    }

    public static function origin(mixed $value): array
    {
        if (! self::isOrigin($value)) {
            throw new RuntimeException('Rehearsal service origins must be plain HTTP on an explicit loopback address.');
        }

        /** @var array{scheme: string, host: string, port?: int} */
        return parse_url((string) $value);
    }
}
