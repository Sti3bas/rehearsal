<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'rehearsal:install {--environment=playwright}';

    protected $description = 'Create an independent Laravel environment for Rehearsal';

    public function handle(): int
    {
        $environment = $this->option('environment');
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $environment)) {
            $this->error('Invalid Rehearsal environment name.');

            return self::FAILURE;
        }
        $name = '.env.'.$environment;
        $path = base_path($name);
        $file = @fopen($path, 'x');
        if ($file === false) {
            $this->error("$name already exists or cannot be created; it was not overwritten.");

            return self::FAILURE;
        }

        chmod($path, 0600);
        $key = base64_encode(random_bytes(32));
        $data = <<<ENV
APP_ENV=$environment
REHEARSAL_ENVIRONMENTS=$environment
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
            $this->error("Could not complete $name.");

            return self::FAILURE;
        }

        $this->info("Created $name. Keep this file and .rehearsal/ out of version control.");

        return self::SUCCESS;
    }
}
