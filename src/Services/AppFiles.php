<?php
declare(strict_types=1);

namespace RacunatorPanel\Services;

/**
 * Rad sa fajlovima instalacije: bezbedno raspakivanje ZIP-a, provera SHA-256 manifesta i zamena direktorijuma aplikacije
 * preimenovanjem. Koriste je ažuriranje (install/Updater.php) i povratak iz rezervne kopije (Backup::restore).
 *
 * Raspored instalacije:
 *   <koren>/public, src, install, docs   ← menjaju se pri ažuriranju (APP_DIRS)
 *   <koren>/LICENSE, README.md, CHANGELOG.md ← kopiraju se (ROOT_FILES)
 *   <koren>/config.php, .htaccess, storage/   ← nikad se ne diraju
 *   <koren>/releases/<verzija>/          ← raspakovan novi paket pre zamene
 *   <koren>/_old/                         ← prethodna verzija posle zamene (do sledećeg ažuriranja)
 * releases/ i _old/ dobijaju sopstveni .htaccess sa „Require all denied" (protectDir), a paket nosi koreni .htaccess pod
 * imenom htaccess.dist — u releases/ i _old/ nikad nema aktivnog .htaccess-a sa RewriteEngine koji bi poništio zabranu.
 */
final class AppFiles
{
    public const APP_DIRS = ['public', 'src', 'install', 'docs'];
    public const ROOT_FILES = ['LICENSE', 'README.md', 'CHANGELOG.md', 'MANIFEST.sha256'];
    public const MANIFEST = 'MANIFEST.sha256';
    public const OLD_DIR = '_old';
    public const RELEASES_DIR = 'releases';
    /** Koreni .htaccess u paketu (postaje .htaccess tek u korenu instalacije). */
    public const HTACCESS_DIST = 'htaccess.dist';

    /** Sadržaj .htaccess-a za releases/ i _old/: ništa odatle nije dostupno preko veba. */
    public const DENY_HTACCESS = "# Računator panel — privremeni ili stari fajlovi programa; nikad nisu dostupni preko veba.\n"
        . "Options -Indexes\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        . "# <If> se primenjuje posle .htaccess-a poddirektorijuma, pa ni njihovo „Require all granted\" ne poništava zabranu.\n"
        . "<IfModule mod_authz_core.c>\n    <If \"true\">\n        Require all denied\n    </If>\n</IfModule>\n";

    /**
     * Šta sprečava zamenu direktorijuma (prazna lista = može). Premeštanje direktorijuma u drugi roditeljski direktorijum
     * na Linuksu traži pravo pisanja i u sam direktorijum (menja se njegov unos „..").
     * @return list<string>
     */
    public static function swapProblems(string $root): array
    {
        $problems = [];
        if (!is_dir($root) || !is_writable($root)) {
            $problems[] = 'PHP ne može da piše u koren instalacije (' . $root . '). Zatražite od hostinga prava pisanja za PHP korisnika.';
        }
        foreach (self::APP_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (is_dir($path) && !is_writable($path)) {
                $problems[] = 'Direktorijum „' . $dir . '" nije upisiv za PHP (verovatno je otpremljen preko FTP-a pod drugim korisnikom).';
            }
        }
        foreach (self::ROOT_FILES as $file) {
            $path = $root . '/' . $file;
            if (is_file($path) && !is_writable($path)) {
                $problems[] = 'Fajl „' . $file . '" nije upisiv za PHP.';
            }
        }
        return $problems;
    }

    /** Ime unutar ZIP-a je bezbedno (bez apsolutnih putanja, „..", obrnutih kosih crta i kontrolnih znakova). */
    public static function safeEntryName(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || preg_match('/[\x00-\x1F]/', $name) === 1) {
            return false;
        }
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            return false;
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '..' || $part === '.' || $part === '') {
                return false;
            }
        }
        return true;
    }

    /** Raspakuje ZIP u (prazan ili nepostojeći) direktorijum, uz proveru svakog imena. Zahteva ext-zip. */
    public static function extractZip(string $zipFile, string $dest): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ekstenzija „zip" nije uključena — zatražite je od hostinga.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Paket nije ispravan ZIP: ' . basename($zipFile));
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!self::safeEntryName($name)) {
                    throw new \RuntimeException('Paket sadrži nedozvoljeno ime fajla: ' . $name);
                }
            }
            self::ensureDir($dest);
            if (!$zip->extractTo($dest)) {
                throw new \RuntimeException('Raspakivanje nije uspelo (prostor na disku ili prava pisanja).');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Proverava MANIFEST.sha256 u raspakovanom paketu: svaki navedeni fajl postoji i ima isti SHA-256, a u direktorijumu
     * nema nijednog fajla (ni simboličkog linka) kojeg nema u manifestu.
     * @return list<string> greške (prazno = ispravno)
     */
    public static function verifyManifest(string $dir): array
    {
        $file = $dir . '/' . self::MANIFEST;
        if (!is_file($file)) {
            return ['Paket nema ' . self::MANIFEST . '.'];
        }
        $errors = [];
        $listed = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m) || !self::safeEntryName($m[2])) {
                $errors[] = 'Neispravan red u manifestu: ' . $line;
                continue;
            }
            $listed[$m[2]] = true;
            $path = $dir . '/' . $m[2];
            if (is_link($path) || !is_file($path)) {
                $errors[] = 'Nedostaje fajl: ' . $m[2];
            } elseif (!hash_equals($m[1], (string) hash_file('sha256', $path))) {
                $errors[] = 'SHA-256 se ne slaže: ' . $m[2];
            }
        }
        if ($listed === []) {
            $errors[] = 'Manifest je prazan.';
        }
        foreach (self::listFiles($dir) as $rel) {
            if ($rel !== self::MANIFEST && !isset($listed[$rel])) {
                $errors[] = 'Fajl nije u manifestu: ' . $rel;
            }
        }
        return $errors;
    }

    /**
     * Relativne putanje svih fajlova i simboličkih linkova u direktorijumu (rekurzivno, linkovi se ne prate).
     * @return list<string>
     */
    public static function listFiles(string $dir, string $prefix = ''): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $rel = $prefix . $entry;
            if (!is_link($path) && is_dir($path)) {
                array_push($out, ...self::listFiles($path, $rel . '/'));
            } else {
                $out[] = $rel;
            }
        }
        return $out;
    }

    /**
     * Direktorijum u releases/ (pravi releases/ sa zabranom pristupa preko veba). Postojeći direktorijum istog imena se briše.
     */
    public static function stagingDir(string $root, string $name): string
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
            throw new \RuntimeException('Neispravno ime privremenog direktorijuma: ' . $name);
        }
        $releases = $root . '/' . self::RELEASES_DIR;
        self::ensureDir($releases);
        self::protectDir($releases);
        $dir = $releases . '/' . $name;
        if (file_exists($dir) || is_link($dir)) {
            self::removeTree($dir);
        }
        return $dir;
    }

    /** Upisuje .htaccess sa zabranom pristupa (i prazan index.html) u releases/ ili _old/. */
    public static function protectDir(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht) || (string) @file_get_contents($ht) !== self::DENY_HTACCESS) {
            if (@file_put_contents($ht, self::DENY_HTACCESS) === false) {
                throw new \RuntimeException('Ne mogu da upišem ' . $ht . ' (zaštita od pristupa preko veba).');
            }
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    /**
     * Posle raspakivanja u releases/: koreni .htaccess (iz rezervne kopije ili starog paketa) postaje neaktivan
     * htaccess.dist, a config.php iz rezervne kopije se briše (ne vraća se i ne sme da „oživi" kopiju u releases/).
     */
    public static function neutralizeStaging(string $dir): void
    {
        if (is_file($dir . '/.htaccess')) {
            if (!is_file($dir . '/' . self::HTACCESS_DIST)) {
                self::rename($dir . '/.htaccess', $dir . '/' . self::HTACCESS_DIST);
            } elseif (!@unlink($dir . '/.htaccess')) {
                throw new \RuntimeException('Ne mogu da obrišem ' . $dir . '/.htaccess');
            }
        }
        foreach (glob($dir . '/config.php*') ?: [] as $cfg) {
            if (is_file($cfg) && !@unlink($cfg)) {
                throw new \RuntimeException('Ne mogu da obrišem ' . $cfg);
            }
        }
    }

    /** Briše releases/ ako u njemu nije ostalo ništa osim zaštite (.htaccess, index.html). */
    public static function removeEmptyStaging(string $root): void
    {
        $releases = $root . '/' . self::RELEASES_DIR;
        if (!is_dir($releases)) {
            return;
        }
        $rest = array_diff(scandir($releases) ?: [], ['.', '..', '.htaccess', 'index.html']);
        if ($rest === []) {
            @unlink($releases . '/.htaccess');
            @unlink($releases . '/index.html');
            @rmdir($releases);
        }
    }

    /** Verzija iz `src/version.php` datog direktorijuma (ili null). */
    public static function versionOf(string $dir): ?string
    {
        $file = $dir . '/src/version.php';
        if (!is_file($file)) {
            return null;
        }
        // Fajl vraća samo string; čita se bez izvršavanja da neispravan paket ne bi pokrenuo kod.
        $src = (string) file_get_contents($file);
        return preg_match('/return\s+[\'"]([0-9A-Za-z.\-]+)[\'"]\s*;/', $src, $m) === 1 ? $m[1] : null;
    }

    /** Verzija iz `src/version.php` unutar ZIP-a (čita se bez izvršavanja), ili null. */
    public static function versionOfZip(string $zipFile): ?string
    {
        if (!class_exists(\ZipArchive::class) || !is_file($zipFile)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return null;
        }
        $src = $zip->getFromName('src/version.php');
        $zip->close();
        return is_string($src) && preg_match('/return\s+[\'"]([0-9A-Za-z.\-]+)[\'"]\s*;/', $src, $m) === 1 ? $m[1] : null;
    }

    /**
     * Zamenjuje direktorijume aplikacije onima iz `$sourceDir` (koji mora biti na istom fajl-sistemu, unutar korena):
     * trenutni → `_old/`, novi → na mesto. Ako bilo koji korak padne, odmah vraća već urađeno i baca izuzetak.
     * @return list<array{0:string,1:string}> dnevnik izvedenih koraka (za undoSwap)
     */
    public static function swapIn(string $root, string $sourceDir): array
    {
        if (!is_dir($sourceDir . '/src') || !is_dir($sourceDir . '/public')) {
            throw new \RuntimeException('Novi paket nema src/ i public/.');
        }
        $old = $root . '/' . self::OLD_DIR;
        if (file_exists($old)) {
            self::removeTree($old);
        }
        self::ensureDir($old);
        self::protectDir($old);
        $journal = [];
        try {
            foreach (self::APP_DIRS as $dir) {
                if (!is_dir($sourceDir . '/' . $dir)) {
                    continue;
                }
                if (file_exists($root . '/' . $dir)) {
                    self::rename($root . '/' . $dir, $old . '/' . $dir);
                    $journal[] = ['out', $dir];
                }
                self::rename($sourceDir . '/' . $dir, $root . '/' . $dir);
                $journal[] = ['in', $dir];
            }
            foreach (self::ROOT_FILES as $file) {
                if (!is_file($sourceDir . '/' . $file)) {
                    continue;
                }
                $had = is_file($root . '/' . $file);
                if ($had && !@copy($root . '/' . $file, $old . '/' . $file)) {
                    throw new \RuntimeException('Ne mogu da sačuvam ' . $file . ' u ' . self::OLD_DIR . '/.');
                }
                $journal[] = [$had ? 'file' : 'newfile', $file];
                if (!@copy($sourceDir . '/' . $file, $root . '/' . $file)) {
                    throw new \RuntimeException('Ne mogu da upišem ' . $file . '.');
                }
            }
        } catch (\Throwable $e) {
            self::undoSwap($root, $sourceDir, $journal);
            throw $e;
        }
        self::resetOpcache();
        return $journal;
    }

    /** Vraća stanje pre swapIn: novi direktorijumi nazad u `$sourceDir`, stari iz `_old/` na mesto. */
    public static function undoSwap(string $root, string $sourceDir, array $journal): void
    {
        $old = $root . '/' . self::OLD_DIR;
        $errors = [];
        foreach (array_reverse($journal) as [$kind, $name]) {
            try {
                if ($kind === 'in') {
                    self::rename($root . '/' . $name, $sourceDir . '/' . $name);
                } elseif ($kind === 'out') {
                    self::rename($old . '/' . $name, $root . '/' . $name);
                } elseif ($kind === 'file') {
                    if (!@copy($old . '/' . $name, $root . '/' . $name)) {
                        throw new \RuntimeException('Ne mogu da vratim ' . $name);
                    }
                } elseif ($kind === 'newfile') {
                    @unlink($root . '/' . $name);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        self::resetOpcache();
        if ($errors !== []) {
            throw new \RuntimeException('Povratak fajlova nije potpuno uspeo: ' . implode('; ', $errors)
                . '. Prethodna verzija je u direktorijumu ' . self::OLD_DIR . '/ — vratite je ručno preko FTP-a (vidi docs/azuriranje.md).');
        }
    }

    /** Briše keš bajtkoda da posle zamene fajlova ne bi radio stari kod (ako hosting to dozvoljava). */
    public static function resetOpcache(): void
    {
        clearstatcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public static function ensureDir(string $dir, int $mode = 0755): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException('Ne mogu da napravim direktorijum ' . $dir);
        }
    }

    public static function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            $err = error_get_last()['message'] ?? '';
            throw new \RuntimeException('Preimenovanje nije uspelo: ' . $from . ' → ' . $to . ($err !== '' ? ' (' . $err . ')' : ''));
        }
    }

    /** Rekurzivno brisanje (ne prati simboličke linkove). */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException('Ne mogu da obrišem ' . $path);
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException('Ne mogu da obrišem direktorijum ' . $path);
        }
    }

    /** Ukupna veličina fajlova u bajtovima. */
    public static function treeSize(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (!is_dir($path) || is_link($path)) {
            return 0;
        }
        $sum = 0;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $sum += self::treeSize($path . '/' . $entry);
            }
        }
        return $sum;
    }

    /** Slobodan prostor na disku ili null kad hosting ne dozvoljava proveru. */
    public static function freeSpace(string $dir): ?int
    {
        if (!function_exists('disk_free_space')) {
            return null;
        }
        $free = @disk_free_space($dir);
        return $free === false ? null : (int) $free;
    }

    /**
     * Atomičan upis fajla (privremeni fajl + preimenovanje). Sa $mode, privremeni fajl od početka nastaje sa umask 0077
     * (nikad nije čitljiv drugima, ni na trenutak), pa dobija tačno $mode pre preimenovanja.
     */
    public static function writeAtomic(string $file, string $contents, ?int $mode = null): void
    {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $old = $mode !== null ? umask(0077) : null;
        try {
            $ok = @file_put_contents($tmp, $contents, LOCK_EX) !== false;
        } finally {
            if ($old !== null) {
                umask($old);
            }
        }
        if (!$ok) {
            @unlink($tmp);
            throw new \RuntimeException('Ne mogu da upišem ' . $file);
        }
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Ne mogu da upišem ' . $file);
        }
    }

    /** Otvara (pravi) fajl tako da od prvog trenutka ima prava 0600 (umask 0077). @return resource */
    public static function openPrivate(string $file, string $mode = 'wb')
    {
        $old = umask(0077);
        try {
            $fp = @fopen($file, $mode);
        } finally {
            umask($old);
        }
        if ($fp === false) {
            throw new \RuntimeException('Ne mogu da otvorim ' . $file);
        }
        @chmod($file, 0600);
        return $fp;
    }
}
