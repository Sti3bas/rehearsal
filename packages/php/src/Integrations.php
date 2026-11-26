<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

final class Integrations
{
    public function __construct(private Container $container) {}

    /** @return array<string, Integration> */
    public function all(): array
    {
        $integrations = [];
        foreach (config('rehearsal.integrations') as $name => $class) {
            if ($class === false) {
                continue;
            }

            $integration = $this->container->make($class);
            if (! $integration instanceof Integration) {
                throw new RuntimeException("Rehearsal integration [$name] must implement Integration.");
            }

            $integrations[$name] = $integration;
        }

        return $integrations;
    }
}
