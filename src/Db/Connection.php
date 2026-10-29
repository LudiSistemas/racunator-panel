<?php
declare(strict_types=1);

namespace RacunatorPanel\Db;

/**
 * Otvaranje PDO veze iz `db` dela konfiguracije:
 * ['driver' => 'mysql'|'sqlite', 'dsn' => '…', 'user' => '', 'pass' => ''].
 * Za SQLite je dozvoljeno i ['driver' => 'sqlite', 'path' => '/putanja/panel.sqlite'] umesto `dsn`.
 */
final class Connection
{
    public static function open(array $db): \PDO
    {
        $driver = strtolower((string) ($db['driver'] ?? ''));
        $dsn = (string) ($db['dsn'] ?? '');
        if ($driver === '' && $dsn !== '') {
            $driver = strtolower(explode(':', $dsn, 2)[0]);
        }
        if ($driver === 'sqlite' && $dsn === '' && isset($db['path'])) {
            $dsn = 'sqlite:' . (string) $db['path'];
        }
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Nepodržan drajver baze: „' . $driver . '" (dozvoljeno: mysql, sqlite).');
        }
        if ($dsn === '' || !str_starts_with(strtolower($dsn), $driver . ':')) {
            throw new \InvalidArgumentException('Neispravan DSN za drajver ' . $driver . '.');
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($driver === 'mysql') {
            if (!str_contains(strtolower($dsn), 'charset=')) {
                $dsn .= ';charset=utf8mb4';
            }
            $options[\PDO::ATTR_EMULATE_PREPARES] = false;
            $pdo = new \PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), $options);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        }

        $pdo = new \PDO($dsn, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // WAL nije moguć za bazu u memoriji; SQLite tada tiho ostaje u režimu „memory".
        $pdo->query('PRAGMA journal_mode = WAL')->fetchAll();
        return $pdo;
    }

    /** 'mysql' ili 'sqlite'. */
    public static function driver(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** Da li je izuzetak kršenje jedinstvenosti (UNIQUE/PRIMARY KEY) — radi i za MySQL i za SQLite. */
    public static function isUniqueViolation(\PDOException $e): bool
    {
        $info = $e->errorInfo ?? [];
        $sqlState = (string) ($info[0] ?? $e->getCode());
        if ($sqlState !== '23000') {
            return false;
        }
        $code = (int) ($info[1] ?? 0);
        // MySQL 1062 = Duplicate entry; SQLite 19 = SQLITE_CONSTRAINT (proverava se i tekst greške).
        return $code === 1062
            || ($code === 19 && preg_match('/UNIQUE|PRIMARY KEY/i', (string) ($info[2] ?? $e->getMessage())) === 1);
    }
}
