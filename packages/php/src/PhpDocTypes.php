<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class PhpDocTypes
{
    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    public static function paramDocType(
        ReflectionParameter $parameter,
        ReflectionFunctionAbstract $function,
        array $aliasesByClass,
        array &$usedHelpers,
    ): ?string {
        $doc = $function->getDocComment();
        if (! is_string($doc)) {
            return null;
        }
        $tag = self::extractParamTag($doc, $parameter->getName());

        return $tag !== null ? self::docToTs($tag, $aliasesByClass, $usedHelpers, forInput: true) : null;
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    public static function paramType(
        ReflectionParameter $parameter,
        ReflectionFunctionAbstract $function,
        array $aliasesByClass,
        array &$usedHelpers,
    ): string {
        $native = self::reflectionToTs($parameter->getType(), $aliasesByClass, $usedHelpers, forInput: true);
        $parsed = self::paramDocType($parameter, $function, $aliasesByClass, $usedHelpers);
        if ($parsed !== null && ($parsed !== 'unknown' || $native === 'unknown')) {
            return $parsed;
        }

        return $native;
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    public static function returnType(
        ReflectionFunctionAbstract $function,
        array $aliasesByClass,
        array &$usedHelpers,
    ): string {
        $type = $function->getReturnType();
        $native = $type === null
            ? 'unknown'
            : self::reflectionToTs($type, $aliasesByClass, $usedHelpers, forInput: false);

        $doc = $function->getDocComment();
        if (is_string($doc)) {
            $tag = self::extractReturnTag($doc);
            if ($tag !== null) {
                $parsed = self::docToTs($tag, $aliasesByClass, $usedHelpers, forInput: false);
                if ($parsed !== null && ($parsed !== 'unknown' || $native === 'unknown')) {
                    return $parsed;
                }
            }
        }

        return $native;
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    public static function reflectionToTs(
        ?ReflectionType $type,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): string {
        if ($type === null) {
            return 'unknown';
        }
        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $member) {
                $parts[] = self::reflectionToTs($member, $aliasesByClass, $usedHelpers, $forInput);
            }

            return implode(' | ', array_values(array_unique($parts)));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return 'unknown';
        }
        if (! $type instanceof ReflectionNamedType) {
            return 'unknown';
        }

        $name = $type->getName();
        $base = match ($name) {
            'int', 'float' => 'number',
            'string' => 'string',
            'bool', 'true', 'false' => 'boolean',
            'array' => $forInput ? 'unknown[] | Record<string, unknown>' : 'unknown',
            'null', 'void' => 'null',
            'mixed', 'object' => 'unknown',
            default => self::classToTs($name, $aliasesByClass, $usedHelpers, $forInput),
        };

        if ($type->allowsNull() && $base !== 'null' && ! str_contains($base, '| null')) {
            return $base.' | null';
        }

        return $base;
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    public static function docToTs(
        string $expr,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): ?string {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $pos = 0;
        $result = self::parseUnion($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
        self::skipWs($expr, $pos);
        if ($result === null || $pos !== strlen($expr)) {
            return null;
        }

        return $result;
    }

    private static function extractParamTag(string $doc, string $paramName): ?string
    {
        $target = '$'.$paramName;
        foreach (self::extractTags($doc, '@param') as $rest) {
            $idx = self::findParamVar($rest, $target);
            if ($idx !== null) {
                $type = trim(substr($rest, 0, $idx));

                return $type !== '' ? $type : null;
            }
        }

        return null;
    }

    private static function extractReturnTag(string $doc): ?string
    {
        foreach (self::extractTags($doc, '@return') as $rest) {
            if ($rest !== '') {
                return self::stripTrailingDescription($rest);
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function extractTags(string $doc, string $tag): array
    {
        $lines = preg_split('/\r?\n/', $doc) ?: [];
        $results = [];
        $current = null;
        $prefix = $tag.' ';

        foreach ($lines as $line) {
            $cleaned = trim(preg_replace('/^\s*(?:\/\*\*|\*\/|\*)\s?/', '', $line) ?? '');
            if ($cleaned === '' || $cleaned === '/') {
                continue;
            }
            if (str_starts_with($cleaned, '@')) {
                if ($current !== null) {
                    $results[] = trim($current);
                    $current = null;
                }
                if (str_starts_with($cleaned, $prefix)) {
                    $current = trim(substr($cleaned, strlen($prefix)));
                }
            } elseif ($current !== null) {
                $current .= ' '.$cleaned;
            }
        }

        if ($current !== null) {
            $results[] = trim($current);
        }

        return $results;
    }

    private static function findParamVar(string $text, string $target): ?int
    {
        $depth = 0;
        $inQuote = null;
        $len = strlen($text);
        $targetLen = strlen($target);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuote !== null) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                } elseif ($ch === $inQuote) {
                    $inQuote = null;
                }

                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $inQuote = $ch;
            } elseif ($ch === '{' || $ch === '<' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === '>' || $ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && substr($text, $i, $targetLen) === $target) {
                $after = $text[$i + $targetLen] ?? '';
                if ($after === '' || ctype_space($after)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function stripTrailingDescription(string $text): string
    {
        $depth = 0;
        $inQuote = null;
        $len = strlen($text);
        $lastTokenEnd = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuote !== null) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                } elseif ($ch === $inQuote) {
                    $inQuote = null;
                    $lastTokenEnd = $i + 1;
                }

                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $inQuote = $ch;
            } elseif ($ch === '{' || $ch === '<' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === '>' || $ch === ')') {
                $depth = max(0, $depth - 1);
                $lastTokenEnd = $i + 1;
            } elseif ($depth === 0 && ctype_space($ch)) {
                $peek = $i;
                while ($peek < $len && ctype_space($text[$peek])) {
                    $peek++;
                }
                $next = $text[$peek] ?? '';
                if ($next === '|' || $next === '&') {
                    $i = $peek;
                    while ($i + 1 < $len && ctype_space($text[$i + 1])) {
                        $i++;
                    }

                    continue;
                }

                return trim(substr($text, 0, $i));
            } else {
                $lastTokenEnd = $i + 1;
            }
        }

        return trim(substr($text, 0, $lastTokenEnd));
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    private static function parseUnion(
        string $expr,
        int &$pos,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): ?string {
        $parts = [];
        while (true) {
            self::skipWs($expr, $pos);
            $part = self::parsePrimary($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            if ($part === null) {
                return null;
            }
            $parts[] = $part;
            self::skipWs($expr, $pos);
            if (($expr[$pos] ?? '') === '|') {
                $pos++;

                continue;
            }
            break;
        }

        return implode(' | ', array_values(array_unique($parts)));
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    private static function parsePrimary(
        string $expr,
        int &$pos,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): ?string {
        self::skipWs($expr, $pos);
        $len = strlen($expr);
        if ($pos >= $len) {
            return null;
        }

        if ($expr[$pos] === '?') {
            $pos++;
            $inner = self::parsePrimary($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);

            return $inner === null ? null : $inner.' | null';
        }

        if ($expr[$pos] === '(') {
            $pos++;
            $inner = self::parseUnion($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            self::skipWs($expr, $pos);
            if ($inner === null || ($expr[$pos] ?? '') !== ')') {
                return null;
            }
            $pos++;
            $type = '('.$inner.')';
            while (substr($expr, $pos, 2) === '[]') {
                $pos += 2;
                $type = $type.'[]';
            }

            return $type;
        }

        if ($expr[$pos] === '\'' || $expr[$pos] === '"') {
            $quote = $expr[$pos++];
            $value = '';
            while ($pos < $len && $expr[$pos] !== $quote) {
                if ($expr[$pos] === '\\' && $pos + 1 < $len) {
                    $pos++;
                }
                $value .= $expr[$pos++];
            }
            if (($expr[$pos] ?? '') !== $quote) {
                return null;
            }
            $pos++;

            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (preg_match('/\G-?\d+(?:\.\d+)?/', $expr, $m, 0, $pos) === 1) {
            $pos += strlen($m[0]);

            return $m[0];
        }

        if (preg_match('/\G\\\\?[A-Za-z_][A-Za-z0-9_\\\\-]*/', $expr, $m, 0, $pos) !== 1) {
            return null;
        }
        $ident = $m[0];
        $pos += strlen($ident);
        self::skipWs($expr, $pos);

        if ($ident === 'array' && ($expr[$pos] ?? '') === '{') {
            $type = self::parseShape($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            if ($type === null) {
                return null;
            }
        } elseif (($expr[$pos] ?? '') === '<') {
            $args = self::parseGenericArgs($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            if ($args === null) {
                return null;
            }
            $type = self::genericToTs($ident, $args);
        } else {
            $type = self::atomToTs($ident, $aliasesByClass, $usedHelpers, $forInput);
        }

        while (substr($expr, $pos, 2) === '[]') {
            $pos += 2;
            $type = str_contains($type, ' | ') ? '('.$type.')[]' : $type.'[]';
        }

        return $type;
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    private static function parseShape(
        string $expr,
        int &$pos,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): ?string {
        $pos++; // '{'
        $fields = [];
        while (true) {
            self::skipWs($expr, $pos);
            if (($expr[$pos] ?? '') === '}') {
                $pos++;
                break;
            }
            $key = null;
            if (($expr[$pos] ?? '') === '\'' || ($expr[$pos] ?? '') === '"') {
                $quote = $expr[$pos++];
                $raw = '';
                while ($pos < strlen($expr) && $expr[$pos] !== $quote) {
                    if ($expr[$pos] === '\\' && $pos + 1 < strlen($expr)) {
                        $pos++;
                    }
                    $raw .= $expr[$pos++];
                }
                if (($expr[$pos] ?? '') !== $quote) {
                    return null;
                }
                $pos++;
                $key = $raw;
            } elseif (preg_match('/\G[A-Za-z0-9_-]+/', $expr, $m, 0, $pos) === 1) {
                $key = $m[0];
                $pos += strlen($key);
            } else {
                return null;
            }

            self::skipWs($expr, $pos);
            $optional = false;
            if (($expr[$pos] ?? '') === '?') {
                $optional = true;
                $pos++;
                self::skipWs($expr, $pos);
            }
            if (($expr[$pos] ?? '') !== ':') {
                return null;
            }
            $pos++;
            $valueType = self::parseUnion($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            if ($valueType === null) {
                return null;
            }
            $quotedKey = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) === 1
                ? $key
                : json_encode($key, JSON_THROW_ON_ERROR);
            $fields[] = $quotedKey.($optional ? '?' : '').': '.$valueType;
            self::skipWs($expr, $pos);
            if (($expr[$pos] ?? '') === ',') {
                $pos++;

                continue;
            }
            if (($expr[$pos] ?? '') === '}') {
                $pos++;
                break;
            }

            return null;
        }

        return $fields === [] ? 'Record<string, never>' : '{ '.implode('; ', $fields).' }';
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     * @return list<string>|null
     */
    private static function parseGenericArgs(
        string $expr,
        int &$pos,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): ?array {
        $pos++; // '<'
        $args = [];
        while (true) {
            self::skipWs($expr, $pos);
            if (($expr[$pos] ?? '') === '>') {
                $pos++;
                break;
            }
            $arg = self::parseUnion($expr, $pos, $aliasesByClass, $usedHelpers, $forInput);
            if ($arg === null) {
                return null;
            }
            $args[] = $arg;
            self::skipWs($expr, $pos);
            if (($expr[$pos] ?? '') === ',') {
                $pos++;

                continue;
            }
            if (($expr[$pos] ?? '') === '>') {
                $pos++;
                break;
            }

            return null;
        }

        return $args;
    }

    /** @param list<string> $args */
    private static function genericToTs(string $ident, array $args): string
    {
        return match ($ident) {
            'list', 'non-empty-list' => isset($args[0])
                ? (str_contains($args[0], ' | ') ? '('.$args[0].')[]' : $args[0].'[]')
                : 'unknown[]',
            'array', 'non-empty-array' => match (count($args)) {
                1 => str_contains($args[0], ' | ') ? '('.$args[0].')[]' : $args[0].'[]',
                2 => $args[0] === 'number'
                    ? (str_contains($args[1], ' | ') ? '('.$args[1].')[]' : $args[1].'[]')
                    : "Record<{$args[0]}, {$args[1]}>",
                default => 'Record<string, unknown>',
            },
            'int' => 'number',
            'class-string' => 'string',
            default => 'unknown',
        };
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    private static function atomToTs(
        string $ident,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): string {
        return match (strtolower($ident)) {
            'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'float', 'double', 'numeric' => 'number',
            'string', 'non-empty-string', 'non-falsy-string', 'numeric-string', 'literal-string', 'class-string' => 'string',
            'bool', 'boolean' => 'boolean',
            'true' => 'true',
            'false' => 'false',
            'null', 'void' => 'null',
            'array', 'iterable' => 'Record<string, unknown>',
            'mixed', 'object', 'scalar' => 'unknown',
            default => self::classToTs(ltrim($ident, '\\'), $aliasesByClass, $usedHelpers, $forInput),
        };
    }

    /**
     * @param  array<class-string, string>  $aliasesByClass
     * @param  array<string, true>  $usedHelpers
     */
    private static function classToTs(
        string $class,
        array $aliasesByClass,
        array &$usedHelpers,
        bool $forInput,
    ): string {
        if (is_a($class, BackedEnum::class, true)) {
            $cases = array_map(
                static fn (BackedEnum $case) => json_encode($case->value, JSON_THROW_ON_ERROR),
                $class::cases(),
            );

            return $cases === [] ? 'never' : implode(' | ', $cases);
        }
        if (is_a($class, DateTimeInterface::class, true)) {
            return $forInput || is_a($class, JsonSerializable::class, true) ? 'string' : 'unknown';
        }
        if (is_a($class, Model::class, true)) {
            $alias = $aliasesByClass[$class] ?? null;
            if ($alias !== null) {
                if ($forInput) {
                    $usedHelpers['FactoryModelRef'] = true;

                    return 'FactoryModelRef<'.json_encode($alias, JSON_THROW_ON_ERROR).'> | number | string';
                }
                $usedHelpers['FactoryModel'] = true;

                return 'FactoryModel<'.json_encode($alias, JSON_THROW_ON_ERROR).'>';
            }

            return $forInput ? 'number | string' : 'Record<string, unknown>';
        }

        return 'unknown';
    }

    private static function skipWs(string $expr, int &$pos): void
    {
        $len = strlen($expr);
        while ($pos < $len && ctype_space($expr[$pos])) {
            $pos++;
        }
    }
}
