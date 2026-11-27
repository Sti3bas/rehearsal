<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Callback
{
    public function __construct(
        public ?string $path = null,
    ) {}
}
