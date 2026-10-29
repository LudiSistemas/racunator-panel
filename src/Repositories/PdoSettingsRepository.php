<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Db\Connection;

/**
 * Podešavanja u tabeli `settings(name, value)`. Poznati ključevi: accountant_name, public_url, version, install_lock,
 * api_daily_limit, totp_last_step.<id nalog>…
 */
final class PdoSettingsRepository implements SettingsRepository, AtomicCounter
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['value'] : $default;
    }

    public function set(string $key, string $value): void
    {
        $update = $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ?');
        $update->execute([$value, $key]);
        if ($update->rowCount() > 0 || $this->get($key) !== null) {
            // MySQL vraća 0 izmenjenih redova kad je vrednost ista — zato dodatna provera postojanja.
            return;
        }
        try {
            $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$key, $value]);
        } catch (\PDOException $e) {
            if (!Connection::isUniqueViolation($e)) {
                throw $e;
            }
            $update->execute([$value, $key]);
        }
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$key]);
    }

    public function advanceCounter(string $key, int $value): bool
    {
        // Jedna UPDATE naredba sa uslovom je atomična i na MySQL-u i na SQLite-u: od dva istovremena zahteva sa istom
        // vrednošću samo jedan menja red.
        $cast = Connection::driver($this->pdo) === 'mysql' ? 'CAST(value AS SIGNED)' : 'CAST(value AS INTEGER)';
        $update = $this->pdo->prepare('UPDATE settings SET value = ? WHERE name = ? AND ' . $cast . ' < ?');
        $update->execute([(string) $value, $key, $value]);
        if ($update->rowCount() > 0) {
            return true;
        }
        if ($this->get($key) !== null) {
            return false;
        }
        try {
            $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$key, (string) $value]);
            return true;
        } catch (\PDOException $e) {
            if (!Connection::isUniqueViolation($e)) {
                throw $e;
            }
            // Drugi zahtev je upravo upisao red — ponovo, sa uslovom.
            $update->execute([(string) $value, $key, $value]);
            return $update->rowCount() > 0;
        }
    }
}
