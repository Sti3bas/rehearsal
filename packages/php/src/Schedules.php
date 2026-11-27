<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Application as ArtisanApplication;
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
        if (is_array($configured) && array_key_exists('discover', $configured) && is_bool($configured['discover'])) {
            $discover = $configured['discover'];
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
        $binary = trim(ArtisanApplication::artisanBinary(), "\"'");
        $pattern = '/(?:^|\s)(?:["\']?)(?:'.preg_quote($binary, '/').'|(?:[^\s"\']*[\/\\\\])?artisan)(?:["\']?)\s+(.+)$/';
        if (preg_match($pattern, $command, $match) !== 1) {
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
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (str_starts_with($token, '--')) {
                if (str_contains($token, '=')) {
                    [$opt, $val] = explode('=', $token, 2);
                    $optName = substr($opt, 2);
                    $isArray = $definition !== null && $definition->hasOption($optName) && $definition->getOption($optName)->isArray();
                    $this->appendOption($fixed, $opt, $val, $isArray);
                    $displayTokens[] = $token;
                } else {
                    $optName = substr($token, 2);
                    $option = $definition !== null && $definition->hasOption($optName) ? $definition->getOption($optName) : null;
                    if ($option !== null && $option->acceptValue() && $i + 1 < $count && ! str_starts_with($tokens[$i + 1], '-')) {
                        $val = $tokens[++$i];
                        $this->appendOption($fixed, $token, $val, $option->isArray());
                        $displayTokens[] = "{$token}={$val}";
                    } else {
                        $fixed[$token] = true;
                        $displayTokens[] = $token;
                    }
                }

                continue;
            }

            if (str_starts_with($token, '-') && strlen($token) > 1) {
                $shortName = substr($token, 1);
                $option = $definition !== null && $definition->hasShortcut($shortName) ? $definition->getOptionForShortcut($shortName) : null;
                if ($option !== null && $option->acceptValue() && $i + 1 < $count && ! str_starts_with($tokens[$i + 1], '-')) {
                    $val = $tokens[++$i];
                    $this->appendOption($fixed, '--'.$option->getName(), $val, $option->isArray());
                    $displayTokens[] = $token;
                    $displayTokens[] = $val;
                } else {
                    $fixed[$token] = true;
                    $displayTokens[] = $token;
                }

                continue;
            }

            if ($definition !== null && str_contains($token, '=')) {
                [$arg, $val] = explode('=', $token, 2);
                if ($definition->hasArgument($arg)) {
                    $isArray = $definition->getArgument($arg)->isArray();
                    $this->appendOption($fixed, $arg, $val, $isArray);
                    $displayTokens[] = $val;
                    if (! $isArray) {
                        $positionalIndex++;
                    }

                    continue;
                }
            }

            if (isset($positionalNames[$positionalIndex])) {
                $argName = $positionalNames[$positionalIndex];
                $isArray = $definition !== null && $definition->getArgument($argName)->isArray();
                $this->appendOption($fixed, $argName, $token, $isArray);
                if (! $isArray) {
                    $positionalIndex++;
                }
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

    /**
     * @param  array<string, mixed>  $fixed
     */
    private function appendOption(array &$fixed, string $key, string $value, bool $isArray): void
    {
        if ($isArray) {
            $existing = $fixed[$key] ?? [];
            $fixed[$key] = is_array($existing) ? [...$existing, $value] : [$existing, $value];

            return;
        }

        $fixed[$key] = $value;
    }

    /** @return list<string> */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $current = '';
        $inToken = false;
        $quote = null;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && in_array($input[$i + 1], ['"', '\\', '$', '`'], true)) {
                    $current .= $input[++$i];
                } else {
                    $current .= $char;
                }

                continue;
            }

            if (ctype_space($char)) {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }

                continue;
            }

            $inToken = true;
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $current .= $input[++$i];
            } else {
                $current .= $char;
            }
        }

        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
