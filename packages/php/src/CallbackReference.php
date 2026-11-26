<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\Request;

interface CallbackReference
{
    public function verifiedReference(Request $request): ?string;
}
