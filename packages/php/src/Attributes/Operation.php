<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class Operation
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  array{input?: string, output?: string}  $types
     */
    public function __construct(
        public ?string $name = null,
        public array $rules = [],
        public array $types = [],
    ) {}
}
