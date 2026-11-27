<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Generator;
use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use PhpToken;

final class ClassDiscovery
{
    public const array DEFAULT_HELPER_PATHS = ['tests/Rehearsal', 'tests/Helpers', 'app/Rehearsal'];

    /**
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public static function classes(array $paths): array
    {
        $files = new IlluminateFilesystem;
        $seen = [];
        $found = [];
        foreach ($paths as $path) {
            $resolved = preg_match('~^(?:[/\\\\]|[a-z]:[/\\\\])~i', $path) ? $path : base_path($path);
            if (! $files->isDirectory($resolved)) {
                continue;
            }
            foreach ($files->allFiles($resolved) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::declarations($file->getContents()) as $class) {
                    if (isset($seen[$class]) || ! class_exists($class)) {
                        continue;
                    }
                    $seen[$class] = true;
                    $found[] = $class;
                }
            }
        }

        return $found;
    }

    /** @return Generator<string> */
    private static function declarations(string $source): Generator
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token) => ! $token->isIgnorable()));
        $namespace = '';
        foreach ($tokens as $index => $token) {
            $prev = $index > 0 ? $tokens[$index - 1] : null;
            $next = $tokens[$index + 1] ?? null;
            if ($token->id === T_NAMESPACE) {
                $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text.'\\' : '';
            } elseif ($token->id === T_CLASS && $prev?->id !== T_DOUBLE_COLON && $prev?->id !== T_NEW && $next?->id === T_STRING) {
                yield $namespace.$next->text;
            }
        }
    }
}
