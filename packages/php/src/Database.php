<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class Database implements Integration
{
    private const CONNECTION = 'rehearsal';

    private array $originalConnections = [];

    public function isolateConnections(): void
    {
        $this->originalConnections = config('database.connections', []);
        if (app()->resolved('db')) {
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::purge($name);
            }
        }
        config(['database.connections' => []]);
    }

    public function provision(Run $run): void
    {
        $template = $this->template($run);
        if ($this->driver() === 'sqlite') {
            if (is_file($template) || ! touch($template)) {
                throw new RuntimeException('Could not create the SQLite baseline.');
            }
        } else {
            $pdo = $this->admin();
            $this->create($pdo, $run, $template);
        }

        $this->select($template);
        try {
            $this->artisan('migrate', ['--database' => self::CONNECTION, '--force' => true]);
            if (is_string($seeder = config('rehearsal.database.seeder'))) {
                $this->artisan('db:seed', ['--class' => $seeder, '--database' => self::CONNECTION, '--force' => true]);
            }
            if ($this->driver() === 'sqlite') {
                DB::connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
            }
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    public function bind(Attempt $attempt): void
    {
        $this->select($this->slot($attempt));
    }

    public function reset(Attempt $attempt): void
    {
        DB::purge(self::CONNECTION);
        $slot = $this->slot($attempt);
        $template = $this->template($attempt->run);
        if ($this->driver() === 'sqlite') {
            $temporary = $slot.'.next';
            if (! copy($template, $temporary)) {
                throw new RuntimeException('Could not copy the SQLite baseline.');
            }
            foreach ([$slot.'-wal', $slot.'-shm'] as $sidecar) {
                if (is_file($sidecar) && ! unlink($sidecar)) {
                    throw new RuntimeException('Could not clear a SQLite sidecar.');
                }
            }
            if (! rename($temporary, $slot)) {
                throw new RuntimeException('Could not replace the SQLite slot.');
            }

            return;
        }

        $pdo = $this->admin();
        $this->drop($pdo, $attempt->run, $slot);
        $suffix = $this->driver() === 'pgsql' ? ' TEMPLATE '.$this->identifier($template) : '';
        $this->create($pdo, $attempt->run, $slot, $suffix);
        if ($this->driver() === 'mysql') {
            $this->copyMysql($pdo, $template, $slot);
        }
    }

    public function cleanup(Run $run): void
    {
        if ($this->driver() === 'sqlite') {
            return;
        }

        $owned = glob($run->path('owned-rehearsal_'.$run->id.'_*')) ?: [];
        if ($owned === []) {
            return;
        }
        DB::purge(self::CONNECTION);
        $pdo = $this->admin();
        foreach ($owned as $path) {
            $this->drop($pdo, $run, substr(basename($path), strlen('owned-')));
        }
    }

    private function driver(): string
    {
        $driver = config('rehearsal.database.driver');
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new RuntimeException('Rehearsal requires sqlite, mysql or pgsql.');
        }

        return $driver;
    }

    private function template(Run $run): string
    {
        return $this->driver() === 'sqlite' ? $run->path('template.sqlite') : 'rehearsal_'.$run->id.'_template';
    }

    private function slot(Attempt $attempt): string
    {
        return $this->driver() === 'sqlite' ? $attempt->path('database.sqlite') : 'rehearsal_'.$attempt->run->id.'_s'.$attempt->slot;
    }

    private function select(string $database): void
    {
        $aliases = array_unique([$this->driver(), ...config('rehearsal.database.aliases', [])]);
        foreach ($aliases as $alias) {
            if (! is_string($alias) || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $alias) || $alias === self::CONNECTION || $alias === 'rehearsal_unbound') {
                throw new RuntimeException('Invalid Rehearsal database alias.');
            }
            DB::purge($alias);
        }
        DB::purge(self::CONNECTION);
        $connection = $this->driver() === 'sqlite' ? [
            'driver' => 'sqlite',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
        ] : $this->serverConfig();
        DB::extend('rehearsal-alias', static fn () => DB::connection(self::CONNECTION));
        config([
            'database.connections' => [self::CONNECTION => array_replace($connection, ['database' => $database])]
                + array_fill_keys($aliases, ['driver' => 'rehearsal-alias']),
            'database.default' => self::CONNECTION,
        ]);
    }

    private function serverConfig(): array
    {
        $driver = $this->driver();
        $connection = $this->originalConnections[$driver] ?? null;
        if (config('rehearsal.database.disposable_server') !== true
            || ! is_array($connection)
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', '::1'], true)
            || ! empty($connection['url'])
            || ! empty($connection['unix_socket'])
            || isset($connection['read'])
            || isset($connection['write'])
        ) {
            throw new RuntimeException('Server databases require an explicitly disposable loopback connection without a URL or socket.');
        }
        return $connection;
    }

    private function admin(): PDO
    {
        $connection = $this->serverConfig();
        $connection['database'] = $this->driver() === 'pgsql' ? 'postgres' : 'information_schema';

        return DB::build($connection)->getPdo();
    }

    private function identifier(string $value): string
    {
        $quote = $this->driver() === 'mysql' ? "\x60" : '"';

        return $quote.str_replace($quote, $quote.$quote, $value).$quote;
    }

    private function marker(Run $run, string $name): string
    {
        if (! preg_match('/\Arehearsal_'.preg_quote($run->id, '/').'_(?:template|s[1-9][0-9]?)\z/', $name)) {
            throw new RuntimeException('Rehearsal refused a database outside this run.');
        }

        return $run->path('owned-'.$name);
    }

    private function create(PDO $pdo, Run $run, string $name, string $suffix = ''): void
    {
        $marker = $this->marker($run, $name);
        $pdo->exec('CREATE DATABASE '.$this->identifier($name).$suffix);
        if (! touch($marker)) {
            $pdo->exec('DROP DATABASE '.$this->identifier($name));
            throw new RuntimeException('Could not record database ownership.');
        }
    }

    private function drop(PDO $pdo, Run $run, string $name): void
    {
        $marker = $this->marker($run, $name);
        if (is_file($marker)) {
            $pdo->exec('DROP DATABASE IF EXISTS '.$this->identifier($name));
            if (! unlink($marker)) {
                throw new RuntimeException('Could not update database ownership.');
            }
        }
    }

    private function copyMysql(PDO $pdo, string $template, string $target): void
    {
        foreach ([
            ['TRIGGERS', 'TRIGGER_SCHEMA'],
            ['ROUTINES', 'ROUTINE_SCHEMA'],
            ['EVENTS', 'EVENT_SCHEMA'],
            ['VIEWS', 'TABLE_SCHEMA'],
        ] as [$table, $column]) {
            $count = $pdo->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column = ".$pdo->quote($template))->fetchColumn();
            if ((int) $count > 0) {
                throw new RuntimeException("Rehearsal MySQL baselines do not yet support $table.");
            }
        }

        $source = $this->identifier($template);
        $destination = $this->identifier($target);
        $tables = $pdo->query('SHOW FULL TABLES FROM '.$source)->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('USE '.$destination);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $identifier = $this->identifier($table);
                [, $ddl] = $pdo->query("SHOW CREATE TABLE $source.$identifier")->fetch(PDO::FETCH_NUM);
                $pdo->exec($ddl);
                $columns = array_column(array_filter(
                    $pdo->query("SHOW FULL COLUMNS FROM $source.$identifier")->fetchAll(PDO::FETCH_ASSOC),
                    static fn ($row) => ! str_contains($row['Extra'], 'GENERATED') || str_contains($row['Extra'], 'DEFAULT_GENERATED'),
                ), 'Field');
                $list = implode(',', array_map($this->identifier(...), $columns));
                $pdo->exec("INSERT INTO $destination.$identifier ($list) SELECT $list FROM $source.$identifier");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $pdo->exec('USE information_schema');
        }
    }

    private function artisan(string $command, array $args): void
    {
        if (Artisan::call($command, $args) !== 0) {
            throw new RuntimeException('Could not prepare the Rehearsal baseline: '.$command);
        }
    }
}
