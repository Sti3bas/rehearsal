<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

final readonly class Attempt
{
    public function __construct(public Run $run, public int $slot, public string $id) {}

    public function path(string $suffix): string
    {
        return $this->run->path('slot-'.$this->slot.'-'.$suffix);
    }
}
