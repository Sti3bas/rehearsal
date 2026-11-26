<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Command;

final class ProvisionCommand extends Command
{
    protected $signature = 'rehearsal:provision';

    public function handle(Protocol $protocol): int
    {
        $protocol->provision();

        return self::SUCCESS;
    }
}
