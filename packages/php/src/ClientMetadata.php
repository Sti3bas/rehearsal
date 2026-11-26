<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

interface ClientMetadata
{
    public function clientMetadata(Attempt $attempt): array;
}
