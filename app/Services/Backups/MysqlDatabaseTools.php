<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Backups for MySQL and MariaDB through the mysqldump and mysql programs
 * (paths in config/backups.php). The password is passed in the MYSQL_PWD
 * environment variable, never on the command line.
 */
final class MysqlDatabaseTools implements DatabaseTools
{
    private const TIMEOUT_SECONDS = 3600;

    public function dump(string $path): void
    {
        $connection = $this->connection();
        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout(self::TIMEOUT_SECONDS)
            ->run([
                config('backups.mysqldump'),
                ...$this->serverArguments($connection),
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$path,
                $connection['database'],
            ]);

        if (! $result->successful() || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('The database could not be backed up: '.$this->problem($result->errorOutput()));
        }
    }

    public function import(string $path, ?string $database = null): void
    {
        $connection = $this->connection();
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        try {
            $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
                ->timeout(self::TIMEOUT_SECONDS)
                ->input($input)
                ->run([
                    config('backups.mysql'),
                    ...$this->serverArguments($connection),
                    '--default-character-set=utf8mb4',
                    $database ?? $connection['database'],
                ]);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (! $result->successful()) {
            throw new RuntimeException('The database could not be restored: '.$this->problem($result->errorOutput()));
        }
    }

    public function dropAllTables(): void
    {
        Schema::dropAllTables();
    }

    public function createDatabase(string $name): void
    {
        DB::statement('create database if not exists `'.$this->name($name).'` character set utf8mb4 collate utf8mb4_unicode_ci');
    }

    public function dropDatabase(string $name): void
    {
        DB::statement('drop database if exists `'.$this->name($name).'`');
    }

    public function tableCounts(?string $database = null): array
    {
        $schema = $this->name($database ?? $this->connection()['database']);
        $tables = DB::select('select table_name as name from information_schema.tables where table_schema = ? and table_type = ? order by table_name', [$schema, 'BASE TABLE']);

        $counts = [];
        foreach ($tables as $table) {
            $name = $this->name((string) $table->name);
            $counts[$name] = (int) DB::selectOne("select count(*) as total from `{$schema}`.`{$name}`")->total;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        $connection = config('database.connections.'.config('database.default'));
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Backups need a MySQL or MariaDB database.');
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function serverArguments(array $connection): array
    {
        return [
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? 'root'),
        ];
    }

    /** Database and table names are only ever letters, digits and underscores here. */
    private function name(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("Unexpected database or table name: {$name}");
        }

        return $name;
    }

    private function problem(string $output): string
    {
        $line = trim(strtok(trim($output), "\n") ?: '');

        return $line === '' ? 'the database program did not answer. Check BACKUP_MYSQLDUMP_PATH and BACKUP_MYSQL_PATH.' : $line;
    }
}
