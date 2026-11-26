<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Closure;
use Illuminate\Support\Facades\Validator;
use LogicException;

final class Operations
{
    private array $entries = [];

    public function __construct(private readonly array $configured = []) {}

    public function register(string $name, array $rules, Closure $handler): void
    {
        if (isset($this->entries[$name]) || isset($this->configured[$name])) {
            throw new LogicException("Rehearsal operation [$name] is already registered.");
        }

        $this->entries[$name] = [$rules, $handler];
    }

    public function invoke(string $name, array $data): mixed
    {
        if (isset($this->configured[$name])) {
            $entry = $this->configured[$name];
            $validated = Validator::validate($data, $entry['rules']);
            $handler = app($entry['handler']);

            return $handler($validated);
        }

        abort_unless(isset($this->entries[$name]), 404, 'Rehearsal operation is not registered.');
        [$rules, $handler] = $this->entries[$name];

        return $handler(Validator::validate($data, $rules));
    }
}
