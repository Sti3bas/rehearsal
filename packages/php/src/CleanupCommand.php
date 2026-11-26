<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Command;

final class CleanupCommand extends Command
{
    protected $signature = 'rehearsal:cleanup';

    public function handle(Protocol $protocol): int
    {
        $protocol->cleanup();

        return self::SUCCESS;
    }
}
