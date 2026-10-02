<?php

namespace App\Services\Backups;

/**
 * What backups need from the database server. MysqlDatabaseTools uses the
 * mysqldump and mysql programs (MySQL and MariaDB); tests use a fake.
 */
interface DatabaseTools
{
    /** Writes the application database to an SQL file (a consistent snapshot; no tables are locked). */
    public function dump(string $path): void;

    /** Runs an SQL file against the application database, or another database on the same server. */
    public function import(string $path, ?string $database = null): void;

    /** Drops every table of the application database (before a restore). */
    public function dropAllTables(): void;

    public function createDatabase(string $name): void;

    public function dropDatabase(string $name): void;

    /**
     * Row count of every table, of the application database or another one.
     *
     * @return array<string, int>
     */
    public function tableCounts(?string $database = null): array;
}
