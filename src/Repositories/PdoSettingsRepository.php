<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Db\Connection;

/** Podešavanja u tabeli `settings(name, value)`. Poznati ključevi: accountant_name, public_url, version, install_lock… */
final class PdoSettingsRepository implements SettingsRepository
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
}
