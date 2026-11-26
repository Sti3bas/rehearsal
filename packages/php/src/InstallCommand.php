<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'rehearsal:install';

    protected $description = 'Create an independent Laravel environment for Rehearsal';

    public function handle(): int
    {
        $path = base_path('.env.playwright');
        $file = @fopen($path, 'x');
        if ($file === false) {
            $this->error('.env.playwright already exists or cannot be created; it was not overwritten.');

            return self::FAILURE;
        }

        chmod($path, 0600);
        $key = base64_encode(random_bytes(32));
        $data = <<<ENV
APP_ENV=playwright
APP_KEY=base64:$key
APP_DEBUG=false
APP_URL=http://127.0.0.1:8100
DB_CONNECTION=sqlite
MAIL_MAILER=log
BROADCAST_CONNECTION=log
SCOUT_DRIVER=null

ENV;
        $written = fwrite($file, $data);
        fclose($file);
        if ($written !== strlen($data)) {
            $this->error('Could not complete .env.playwright.');

            return self::FAILURE;
        }

        $this->info('Created .env.playwright. Keep this file and .rehearsal/ out of version control.');

        return self::SUCCESS;
    }
}
