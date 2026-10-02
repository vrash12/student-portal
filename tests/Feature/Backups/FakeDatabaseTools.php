<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\DatabaseTools;
use RuntimeException;

/**
 * Stands in for mysqldump/mysql in backup tests: the "database" is a string
 * that dump() writes and import() reads back.
 */
class FakeDatabaseTools implements DatabaseTools
{
    public string $current = "-- fake dump\nINSERT INTO users VALUES (1, 'Original');\n";

    /** @var list<array{sql: string, database: string|null}> */
    public array $imports = [];

    public int $dropped = 0;

    /** @var list<string> */
    public array $created = [];

    public bool $failImport = false;

    public bool $refuseCreate = false;

    public function dump(string $path): void
    {
        file_put_contents($path, $this->current);
    }

    public function import(string $path, ?string $database = null): void
    {
        $sql = (string) file_get_contents($path);
        if ($this->failImport) {
            $this->failImport = false;

            throw new RuntimeException('Simulated import failure.');
        }
        $this->imports[] = ['sql' => $sql, 'database' => $database];
        if ($database === null) {
            $this->current = $sql;
        }
    }

    public function dropAllTables(): void
    {
        $this->dropped++;
        $this->current = '';
    }

    public function createDatabase(string $name): void
    {
        if ($this->refuseCreate) {
            throw new RuntimeException('CREATE command denied to user');
        }
        $this->created[] = $name;
    }

    public function dropDatabase(string $name): void {}

    public function tableCounts(?string $database = null): array
    {
        return ['migrations' => 60, 'users' => 3];
    }
}
