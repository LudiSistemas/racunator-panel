<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * Rezervne kopije instalacije u `storage/backups/<YYYYmmdd-HHMMSS>/`:
 *   fajlovi.zip   — fajlovi instalacije bez storage/, releases/ i _old/ (uključuje config.php; on se pri povratku ne dira)
 *   baza.sql      — MySQL/MariaDB: SQL skripta (DROP + CREATE + INSERT po tabelama), napravljena kroz PDO
 *   baza.sqlite   — SQLite: kopija baze (VACUUM INTO, ili kopija fajla)
 *   podaci.json   — verzija, vreme, drajver, tabele, veličine
 * Čuvaju se poslednje tri (prune). Kopije sadrže heševe lozinki i podatke korisnika — storage/ je zaštićen .htaccess-om,
 * a „Preuzmi" je dostupno samo administratoru.
 */
final class Backup
{
    public const KEEP = 3;
    public const FILES_ZIP = 'fajlovi.zip';
    public const DB_SQL = 'baza.sql';
    public const DB_SQLITE = 'baza.sqlite';
    public const META = 'podaci.json';

    /** Šta se ne pakuje iz korena instalacije. */
    private const EXCLUDE_TOP = ['storage', 'releases', '_old', '.git', 'vendor', 'node_modules', 'build', 'tests', '.phpunit.cache'];

    private readonly string $storage;

    /** @param list<string>|null $tables tabele koje pripadaju panelu (null = sve tabele baze) */
    public function __construct(
        private readonly string $root,
        private readonly \PDO $pdo,
        ?string $storage = null,
        private readonly ?array $tables = null,
    ) {
        $this->storage = rtrim($storage ?? $root . '/storage', '/');
    }

    public function dir(): string
    {
        return $this->storage . '/backups';
    }

    /** Ime kopije je bezbedno za putanju (sprečava `../`). */
    public static function validName(string $name): bool
    {
        return preg_match('/^\d{8}-\d{6}(-\d+)?$/', $name) === 1;
    }

    /**
     * Pravi novu kopiju i briše najstarije preko KEEP.
     * @return array{name:string,created_at:string,version:string,driver:string,tables:list<string>,reason:string,size:int}
     */
    public function create(string $version, string $reason = 'azuriranje'): array
    {
        AppFiles::ensureDir($this->dir(), 0700);
        // Više kopija u istoj sekundi: sufiks veći od svih postojećih (i obrisanih najstarijih), da redosled ostane tačan.
        $base = gmdate('Ymd-His');
        $max = 0;
        foreach (scandir($this->dir()) ?: [] as $entry) {
            if ($entry === $base) {
                $max = max($max, 1);
            } elseif (preg_match('/^' . preg_quote($base, '/') . '-(\d+)$/', $entry, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        $name = $max === 0 ? $base : $base . '-' . ($max + 1);
        $dir = $this->dir() . '/' . $name;
        AppFiles::ensureDir($dir, 0700);
        try {
            $this->zipFiles($dir . '/' . self::FILES_ZIP);
            $driver = self::driver($this->pdo);
            $tables = $driver === 'sqlite' ? $this->dumpSqlite($dir . '/' . self::DB_SQLITE) : $this->dumpMysql($dir . '/' . self::DB_SQL);
            $meta = [
                'name' => $name,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'version' => $version,
                'driver' => $driver,
                'tables' => $tables,
                'reason' => $reason,
                'size' => AppFiles::treeSize($dir),
            ];
            file_put_contents($dir . '/' . self::META, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            AppFiles::removeTree($dir);
            throw new \RuntimeException('Rezervna kopija nije napravljena: ' . $e->getMessage(), 0, $e);
        }
        $this->prune();
        return $meta;
    }

    /** @return list<array{name:string,created_at:string,version:string,driver:string,tables:list<string>,reason:string,size:int}> najnovije prvo */
    public function list(): array
    {
        $out = [];
        foreach (scandir($this->dir()) ?: [] as $entry) {
            if (self::validName($entry) && ($meta = $this->find($entry)) !== null) {
                $out[] = $meta;
            }
        }
        usort($out, static fn (array $a, array $b): int => strnatcmp($b['name'], $a['name']));
        return $out;
    }

    public function find(string $name): ?array
    {
        if (!self::validName($name)) {
            return null;
        }
        $file = $this->dir() . '/' . $name . '/' . self::META;
        if (!is_file($file)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        if (!is_array($meta)) {
            return null;
        }
        $meta['name'] = $name;
        $meta['size'] = AppFiles::treeSize($this->dir() . '/' . $name);
        return $meta + ['created_at' => '', 'version' => '?', 'driver' => '', 'tables' => [], 'reason' => ''];
    }

    /** Putanja do fajla kopije za preuzimanje (fajlovi.zip, baza.sql, baza.sqlite) ili null. */
    public function filePath(string $name, string $file): ?string
    {
        if (!self::validName($name) || !in_array($file, [self::FILES_ZIP, self::DB_SQL, self::DB_SQLITE], true)) {
            return null;
        }
        $path = $this->dir() . '/' . $name . '/' . $file;
        return is_file($path) ? $path : null;
    }

    /** Zadržava najnovijih $keep kopija. */
    public function prune(int $keep = self::KEEP): void
    {
        foreach (array_slice($this->list(), $keep) as $old) {
            AppFiles::removeTree($this->dir() . '/' . $old['name']);
        }
    }

    /**
     * Potpun povratak: fajlovi aplikacije (preko zamene direktorijuma) i baza.
     * @param list<string> $dropAlso tabele panela koje postoje sada a ne u kopiji (dodale ih novije migracije)
     */
    public function restore(string $name, array $dropAlso = []): void
    {
        $this->restoreFiles($name);
        $this->restoreDatabase($name, $dropAlso);
    }

    /** Vraća fajlove aplikacije iz kopije; trenutni završavaju u `_old/`. config.php, .htaccess i storage/ se ne diraju. */
    public function restoreFiles(string $name): void
    {
        $meta = $this->find($name) ?? throw new \RuntimeException('Rezervna kopija ne postoji: ' . $name);
        $zip = $this->dir() . '/' . $meta['name'] . '/' . self::FILES_ZIP;
        if (!is_file($zip)) {
            throw new \RuntimeException('Kopija nema fajlove (' . self::FILES_ZIP . ').');
        }
        $src = $this->root . '/' . AppFiles::RELEASES_DIR . '/vracanje-' . $name;
        if (file_exists($src)) {
            AppFiles::removeTree($src);
        }
        AppFiles::extractZip($zip, $src);
        try {
            AppFiles::swapIn($this->root, $src);
        } finally {
            AppFiles::removeTree($src);
        }
    }

    /** @param list<string> $dropAlso */
    public function restoreDatabase(string $name, array $dropAlso = []): void
    {
        $meta = $this->find($name) ?? throw new \RuntimeException('Rezervna kopija ne postoji: ' . $name);
        $dir = $this->dir() . '/' . $meta['name'];
        if (self::driver($this->pdo) === 'sqlite') {
            $this->restoreSqlite($dir . '/' . self::DB_SQLITE);
        } else {
            $this->restoreMysql($dir . '/' . self::DB_SQL, $dropAlso);
        }
    }

    public static function driver(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** @return list<string> obične tabele baze (bez pogleda i sistemskih tabela) */
    public static function listTables(\PDO $pdo): array
    {
        if (self::driver($pdo) === 'sqlite') {
            $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                ->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $rows = [];
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $r) {
                $rows[] = $r[0];
            }
            sort($rows);
        }
        return array_values(array_map('strval', $rows));
    }

    private function zipFiles(string $target): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ekstenzija „zip" nije uključena.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Ne mogu da napravim ' . $target);
        }
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::EXCLUDE_TOP, true)) {
                continue;
            }
            // Install fajl (samoraspakujući) i privremeni fajlovi se ne čuvaju.
            if (preg_match('/^racunator-panel-install.*\.php$|\.tmp-[0-9a-f]+$/', $entry) === 1) {
                continue;
            }
            $this->addToZip($zip, $this->root . '/' . $entry, $entry);
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Pakovanje fajlova nije uspelo (prostor na disku?).');
        }
    }

    private function addToZip(\ZipArchive $zip, string $path, string $local): void
    {
        if (is_link($path)) {
            return;
        }
        if (is_dir($path)) {
            $zip->addEmptyDir($local);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->addToZip($zip, $path . '/' . $entry, $local . '/' . $entry);
                }
            }
        } elseif (is_file($path) && is_readable($path)) {
            $zip->addFile($path, $local);
        }
    }

    /** @return list<string> */
    private function dumpSqlite(string $target): array
    {
        $tables = self::listTables($this->pdo);
        try {
            $stmt = $this->pdo->prepare('VACUUM INTO ?');
            $stmt->execute([$target]);
        } catch (\PDOException) {
            // Stariji SQLite bez VACUUM INTO: kopija fajla (uz WAL prvo checkpoint).
            $file = $this->sqliteFile();
            $this->pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
            if ($file === null || !@copy($file, $target)) {
                throw new \RuntimeException('Ne mogu da kopiram SQLite bazu.');
            }
        }
        @chmod($target, 0600);
        return $tables;
    }

    private function sqliteFile(): ?string
    {
        foreach ($this->pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? '') === 'main' && ($row['file'] ?? '') !== '') {
                return (string) $row['file'];
            }
        }
        return null;
    }

    /**
     * Vraća SQLite bazu iz kopije bez zamene fajla (otvorene veze ostaju ispravne): ATTACH kopije, pa u jednoj
     * transakciji brisanje svih tabela i ponovno pravljenje iz kopije sa podacima, indeksima i okidačima.
     */
    private function restoreSqlite(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Kopija nema SQLite bazu.');
        }
        $pdo = $this->pdo;
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->prepare('ATTACH DATABASE ? AS bk')->execute([$file]);
        try {
            $pdo->beginTransaction();
            foreach ($pdo->query("SELECT type, name FROM main.sqlite_master WHERE type IN ('view','trigger') AND name NOT LIKE 'sqlite_%'")->fetchAll() as $r) {
                $pdo->exec('DROP ' . strtoupper((string) $r['type']) . ' IF EXISTS main.' . self::qi((string) $r['name']));
            }
            foreach (self::listTables($pdo) as $t) {
                $pdo->exec('DROP TABLE IF EXISTS main.' . self::qi($t));
            }
            $objects = $pdo->query("SELECT type, name, sql FROM bk.sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' "
                . "ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END")->fetchAll();
            foreach ($objects as $o) {
                $pdo->exec((string) $o['sql']);
                if ($o['type'] === 'table') {
                    $pdo->exec('INSERT INTO main.' . self::qi((string) $o['name']) . ' SELECT * FROM bk.' . self::qi((string) $o['name']));
                }
            }
            $hasSeq = (int) $pdo->query("SELECT COUNT(*) FROM bk.sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() > 0;
            $hasMainSeq = (int) $pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() > 0;
            if ($hasSeq && $hasMainSeq) {
                $pdo->exec('DELETE FROM main.sqlite_sequence');
                $pdo->exec('INSERT INTO main.sqlite_sequence (name, seq) SELECT name, seq FROM bk.sqlite_sequence');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('Vraćanje SQLite baze nije uspelo: ' . $e->getMessage(), 0, $e);
        } finally {
            $pdo->exec('DETACH DATABASE bk');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return list<string> */
    private function dumpMysql(string $target): array
    {
        $pdo = $this->pdo;
        $all = self::listTables($pdo);
        $tables = $this->tables === null ? $all : array_values(array_intersect($all, $this->tables));
        $fp = fopen($target, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Ne mogu da otvorim ' . $target);
        }
        @chmod($target, 0600);
        try {
            self::write($fp, "-- Računator panel — rezervna kopija baze (" . gmdate('Y-m-d H:i:s') . " UTC)\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
            foreach ($tables as $t) {
                $create = $pdo->query('SHOW CREATE TABLE ' . self::qm($t))->fetch(\PDO::FETCH_NUM);
                self::write($fp, "\nDROP TABLE IF EXISTS " . self::qm($t) . ";\n" . $create[1] . ";\n");
                $this->dumpMysqlRows($fp, $t);
            }
            self::write($fp, "\nSET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fp);
        }
        return $tables;
    }

    /** @param resource $fp */
    private function dumpMysqlRows($fp, string $table): void
    {
        $pdo = $this->pdo;
        // Bez baferovanja: velike tabele (računi) se ne učitavaju cele u memoriju.
        $buffered = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $pdo->query('SELECT * FROM ' . self::qm($table));
            $batch = [];
            $cols = null;
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $cols ??= '(' . implode(', ', array_map([self::class, 'qm'], array_keys($row))) . ')';
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = match (true) {
                        $v === null => 'NULL',
                        is_int($v), is_float($v) => (string) $v,
                        is_bool($v) => $v ? '1' : '0',
                        default => $pdo->quote((string) $v),
                    };
                }
                $batch[] = '(' . implode(', ', $vals) . ')';
                if (count($batch) >= 100) {
                    self::write($fp, 'INSERT INTO ' . self::qm($table) . ' ' . $cols . ' VALUES ' . implode(', ', $batch) . ";\n");
                    $batch = [];
                }
            }
            $stmt->closeCursor();
            if ($batch !== []) {
                self::write($fp, 'INSERT INTO ' . self::qm($table) . ' ' . $cols . ' VALUES ' . implode(', ', $batch) . ";\n");
            }
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /**
     * Izvršava SQL skriptu koju je napravio dumpMysql: naredba se završava znakom `;` na kraju reda (vrednosti su
     * escape-ovane, pa nemaju sirove prelome reda; CREATE TABLE ima više redova i završava se `;`).
     * @param list<string> $dropAlso
     */
    private function restoreMysql(string $file, array $dropAlso): void
    {
        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Kopija nema SQL skriptu baze.');
        }
        $pdo = $this->pdo;
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($dropAlso as $t) {
                $pdo->exec('DROP TABLE IF EXISTS ' . self::qm((string) $t));
            }
            $stmt = '';
            while (($line = fgets($fp)) !== false) {
                if ($stmt === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $stmt .= $line;
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec(rtrim(trim($stmt), ';'));
                    $stmt = '';
                }
            }
            if (trim($stmt) !== '') {
                throw new \RuntimeException('SQL skripta je nepotpuna.');
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException('Vraćanje MySQL baze nije uspelo: ' . $e->getMessage(), 0, $e);
        } finally {
            fclose($fp);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable) {
            }
        }
    }

    /** @param resource $fp */
    private static function write($fp, string $s): void
    {
        if (fwrite($fp, $s) !== strlen($s)) {
            throw new \RuntimeException('Upis rezervne kopije nije uspeo (prostor na disku?).');
        }
    }

    private static function qm(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function qi(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
