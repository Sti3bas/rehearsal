<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

interface Integration
{
    public function provision(Run $run): void;

    public function bind(Attempt $attempt): void;

    public function reset(Attempt $attempt): void;

    public function cleanup(Run $run): void;
}
