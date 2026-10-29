<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Support;

use RacunatorPanel\Db\Connection;
use RacunatorPanel\Db\Migrator;

/** SQLite baza u memoriji sa primenjenim migracijama. */
final class TestDb
{
    public static function pdo(): \PDO
    {
        $pdo = Connection::open(['driver' => 'sqlite', 'dsn' => 'sqlite::memory:']);
        (new Migrator($pdo))->migrate();
        return $pdo;
    }
}
