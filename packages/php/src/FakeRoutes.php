<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\Route;

final class FakeRoutes
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** @return list<string> */
    public static function keys(): array
    {
        $configured = config('rehearsal.fake_routes', []);
        $keys = [];
        if ($configured === true || (is_array($configured) && ($configured['discover'] ?? false) === true)) {
            foreach (Route::getRoutes() as $route) {
                $uri = '/'.ltrim($route->uri(), '/');
                $pattern = static fn (string $path): string => preg_replace_callback(
                    '/\{([^}?]+)\??\}/',
                    static fn (array $part): string => in_array($route->wheres[$part[1]] ?? null, ['.*', '.+'], true) ? '**' : '*',
                    $path,
                );
                $paths = [$pattern($uri)];
                while (preg_match('~/\{[^/{}]+\?\}$~D', $uri, $optional)) {
                    $uri = substr($uri, 0, -strlen($optional[0])) ?: '/';
                    $paths[] = $pattern($uri);
                }
                foreach (array_intersect($route->methods(), self::METHODS) as $method) {
                    foreach ($paths as $path) {
                        if (self::applicationPath($path)) {
                            $keys[] = $method.' '.self::normalize($path);
                        }
                    }
                }
            }
        }
        foreach (is_array($configured) ? $configured : [] as $route) {
            if (! is_string($route)) {
                continue;
            }
            [$method, $path] = str_starts_with($route, '/') ? [null, $route] : array_pad(explode(' ', $route, 2), 2, '');
            if (self::applicationPath($path)) {
                foreach ($method === null ? self::METHODS : array_intersect([$method], self::METHODS) as $verb) {
                    $keys[] = $verb.' '.self::normalize($path);
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public static function allows(string $method, string $target): bool
    {
        $target = self::normalize($target);
        $path = explode('?', $target, 2)[0];
        if (! self::applicationPath($path)) {
            return false;
        }
        foreach (self::keys() as $key) {
            [$verb, $pattern] = explode(' ', $key, 2);
            [$allowedPath, $allowedQuery] = array_pad(explode('?', $pattern, 2), 2, null);
            $query = explode('?', $target, 2)[1] ?? null;
            if ($method === $verb && self::matchPattern($allowedPath, $path, true)
                && ($allowedQuery === null || ($query !== null && self::matchPattern($allowedQuery, $query, true)))) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $target): string
    {
        [$path, $query] = array_pad(explode('?', $target, 2), 2, null);

        return (rtrim($path, '/') ?: '/').($query === null ? '' : '?'.$query);
    }

    public static function matches(string $pattern, string $path, string $target): bool
    {
        [$pattern, $query] = array_pad(explode('?', $pattern, 2), 2, null);
        $targetQuery = explode('?', $target, 2)[1] ?? null;

        return self::matchPattern($pattern, $path)
            && ($query === null || ($targetQuery !== null && self::matchPattern($query, $targetQuery)));
    }

    private static function matchPattern(string $pattern, string $candidate, bool $candidateIsPattern = false): bool
    {
        $segment = $candidateIsPattern ? '(?:(?!\*\*)[^/])*' : '[^/]*';
        $regex = strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => $segment]);

        return preg_match('~\A'.$regex.'\z~D', $candidate) === 1;
    }

    private static function applicationPath(string $path): bool
    {
        $path = explode('?', $path, 2)[0];

        return str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && $path !== '/_rehearsal' && ! str_starts_with($path, '/_rehearsal/');
    }
}
