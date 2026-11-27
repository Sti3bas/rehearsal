<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class Schedules
{
    /** @return array<string, array{command: string, fixed?: array<string, mixed>, raw?: string}> */
    public function all(): array
    {
        $configured = config('rehearsal.schedule', []);
        $discover = true;
        if (is_array($configured) && array_key_exists('discover', $configured)) {
            $discover = (bool) $configured['discover'];
            unset($configured['discover']);
        }

        $entries = $discover ? $this->discover() : [];

        if (is_array($configured)) {
            foreach ($configured as $name => $definition) {
                if ($definition === false) {
                    unset($entries[$name]);
                } elseif (is_array($definition)) {
                    $entries[$name] = $definition + ['command' => $name];
                }
            }
        }

        return $entries;
    }

    /** @return array<string, array{command: string, fixed?: array<string, mixed>, raw?: string}> */
    private function discover(): array
    {
        try {
            app(ConsoleKernel::class)->bootstrap();
            $schedule = app(Schedule::class);
        } catch (Throwable) {
            return [];
        }

        $commands = [];
        try {
            $commands = Artisan::all();
        } catch (Throwable) {
        }

        $entries = [];
        foreach ($schedule->events() as $event) {
            if (! is_string($event->command) || $event->command === '') {
                continue;
            }
            $parsed = $this->parseEventCommand($event->command, $commands);
            if ($parsed !== null) {
                $entries[$parsed['name']] = $parsed['entry'];
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, \Symfony\Component\Console\Command\Command>  $commands
     * @return array{name: string, entry: array{command: string, fixed?: array<string, mixed>, raw?: string}}|null
     */
    private function parseEventCommand(string $command, array $commands): ?array
    {
        if (preg_match('/(?:^|\s)(?:["\']?)artisan(?:["\']?)\s+(.+)$/', $command, $match) !== 1) {
            return null;
        }

        $raw = trim($match[1]);
        $tokens = $this->tokenize($raw);
        if ($tokens === []) {
            return null;
        }

        $commandName = array_shift($tokens);
        $definition = isset($commands[$commandName]) ? $commands[$commandName]->getDefinition() : null;
        $positionalNames = $definition !== null ? array_keys($definition->getArguments()) : [];
        $positionalIndex = 0;

        $fixed = [];
        $displayTokens = [$commandName];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                if (str_contains($token, '=')) {
                    [$opt, $val] = explode('=', $token, 2);
                    $fixed[$opt] = $val;
                } else {
                    $fixed[$token] = true;
                }
                $displayTokens[] = $token;

                continue;
            }

            if (str_starts_with($token, '-') && strlen($token) > 1) {
                $fixed[$token] = true;
                $displayTokens[] = $token;

                continue;
            }

            if ($definition !== null && str_contains($token, '=')) {
                [$arg, $val] = explode('=', $token, 2);
                if ($definition->hasArgument($arg)) {
                    $fixed[$arg] = $val;
                    $displayTokens[] = $val;
                    $positionalIndex++;

                    continue;
                }
            }

            if (isset($positionalNames[$positionalIndex])) {
                $fixed[$positionalNames[$positionalIndex]] = $token;
                $positionalIndex++;
                $displayTokens[] = $token;

                continue;
            }

            $displayTokens[] = $token;
        }

        $name = implode(' ', $displayTokens);
        $entry = ['command' => $commandName];
        if ($fixed !== []) {
            $entry['fixed'] = $fixed;
        } elseif ($tokens !== []) {
            $entry['raw'] = $raw;
        }

        return ['name' => $name, 'entry' => $entry];
    }

    /** @return list<string> */
    private function tokenize(string $input): array
    {
        preg_match_all('/(?:[^"\'\s]|"(?:\\\\.|[^"\\\\])*"|\'[^\']*\')+/', $input, $matches);
        $tokens = [];
        foreach ($matches[0] as $raw) {
            $tokens[] = preg_replace_callback(
                '/"((?:\\\\.|[^"\\\\])*)"|\'([^\']*)\'/',
                static fn (array $part): string => $part[1] !== '' ? stripcslashes($part[1]) : ($part[2] ?? ''),
                $raw,
            );
        }

        return $tokens;
    }
}
