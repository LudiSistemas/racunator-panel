<?php
declare(strict_types=1);

namespace RacunatorPanel\Db;

/**
 * Migracije baze. Fajlovi `src/Db/migrations/NNNN_naziv.php` vraćaju ['mysql' => [sql…], 'sqlite' => [sql…]].
 * Primenjene verzije se beleže u tabeli `schema_version(version, applied_at)`. `migrate()` je idempotentan:
 * primenjuje samo migracije novije od trenutne verzije. Na SQLite-u svaka migracija ide u transakciji;
 * na MySQL-u DDL ne može u transakciju (implicitni commit), pa su naredbe pisane sa `IF NOT EXISTS` da bi
 * ponovljeno pokretanje posle prekida prošlo bez greške.
 * Koriste ga instaler, ažuriranje i testovi.
 */
final class Migrator
{
    private readonly string $driver;

    public function __construct(private readonly \PDO $pdo, private readonly string $dir = __DIR__ . '/migrations')
    {
        $this->driver = Connection::driver($pdo);
        if (!in_array($this->driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Migracije podržavaju samo mysql i sqlite.');
        }
    }

    /** @return list<string> imena primenjenih migracija (npr. ['0001_init']) */
    public function migrate(): array
    {
        $this->ensureVersionTable();
        $locked = $this->lock();
        try {
            $current = $this->currentVersion();
            $applied = [];
            foreach ($this->available() as $version => [$name, $file]) {
                if ($version <= $current) {
                    continue;
                }
                $this->apply($version, $file);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            if ($locked) {
                $this->unlock();
            }
        }
    }

    public function currentVersion(): int
    {
        try {
            $v = $this->pdo->query('SELECT MAX(version) AS v FROM schema_version')->fetch();
        } catch (\PDOException) {
            return 0;
        }
        return (int) ($v['v'] ?? 0);
    }

    /** Najveća verzija koja postoji među fajlovima migracija. */
    public function latestVersion(): int
    {
        $all = $this->available();
        return $all === [] ? 0 : (int) array_key_last($all);
    }

    /** @return array<int,array{0:string,1:string}> verzija => [ime, putanja], rastuće */
    private function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.php$/', basename($file), $m)) {
                continue;
            }
            $version = (int) $m[1];
            if (isset($out[$version])) {
                throw new \RuntimeException('Dve migracije imaju istu verziju: ' . $m[1]);
            }
            $out[$version] = [$m[1] . '_' . $m[2], $file];
        }
        ksort($out);
        return $out;
    }

    private function apply(int $version, string $file): void
    {
        $sql = (static fn (string $f): mixed => require $f)($file);
        if (!is_array($sql) || !isset($sql[$this->driver]) || !is_array($sql[$this->driver])) {
            throw new \RuntimeException('Migracija ' . basename($file) . ' nema SQL za drajver ' . $this->driver . '.');
        }
        $transactional = $this->driver === 'sqlite';
        if ($transactional) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($sql[$this->driver] as $statement) {
                $this->pdo->exec((string) $statement);
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_version (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$version, gmdate('Y-m-d H:i:s')]);
            if ($transactional) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactional && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \RuntimeException('Migracija ' . basename($file) . ' nije uspela: ' . $e->getMessage(), 0, $e);
        }
    }

    private function ensureVersionTable(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INT NOT NULL PRIMARY KEY, '
                . 'applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } else {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL PRIMARY KEY, '
                . 'applied_at TEXT NOT NULL)');
        }
    }

    /** Na MySQL-u sprečava da dva zahteva istovremeno pokrenu migracije. */
    private function lock(): bool
    {
        if ($this->driver !== 'mysql') {
            return false;
        }
        $got = $this->pdo->query("SELECT GET_LOCK('racunator_panel_migrate', 30) AS l")->fetch();
        if ((int) ($got['l'] ?? 0) !== 1) {
            throw new \RuntimeException('Migracije već pokreće drugi proces — pokušajte ponovo za minut.');
        }
        return true;
    }

    private function unlock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('racunator_panel_migrate')")->fetchAll();
    }
}
