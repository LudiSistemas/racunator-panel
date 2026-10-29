<?php
declare(strict_types=1);

namespace RacunatorPanel\Tools;

/**
 * Pravljenje paketa izdanja (koriste ga tools/build-installer.php, tools/build-release.php i testovi).
 *   ZIP:  public/, src/, install/, docs/, LICENSE, README.md, CHANGELOG.md, .htaccess, storage/.htaccess + MANIFEST.sha256
 *   Stub: tools/stub/installer-stub.php + base64 ZIP posle __halt_compiler()
 * Isti ZIP je i paket za ažuriranje iz panela (racunator-panel-<verzija>.zip).
 */
final class Package
{
    public const DIRS = ['public', 'src', 'install', 'docs'];
    public const FILES = ['LICENSE', 'README.md', 'CHANGELOG.md', '.htaccess', 'storage/.htaccess'];
    public const INSTALLER_NAME = 'racunator-panel-install.php';

    public static function version(string $root): string
    {
        $v = require $root . '/src/version.php';
        if (!is_string($v) || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $v) !== 1) {
            throw new \RuntimeException('src/version.php mora da vrati verziju oblika X.Y.Z.');
        }
        return $v;
    }

    /** @return list<string> relativne putanje fajlova paketa, sortirano */
    public static function files(string $root): array
    {
        $out = [];
        foreach (self::DIRS as $d) {
            if (!is_dir($root . '/' . $d)) {
                throw new \RuntimeException('Nedostaje direktorijum ' . $d . '/');
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $d, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile()) {
                    continue;
                }
                $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                if (self::excluded($rel)) {
                    continue;
                }
                $out[] = $rel;
            }
        }
        foreach (self::FILES as $f) {
            if (is_file($root . '/' . $f)) {
                $out[] = $f;
            } elseif ($f === 'storage/.htaccess' && is_file($root . '/install/templates/storage.htaccess')) {
                $out[] = $f; // pravi se iz šablona (vidi contents())
            } else {
                throw new \RuntimeException('Nedostaje fajl ' . $f);
            }
        }
        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    private static function excluded(string $rel): bool
    {
        $base = basename($rel);
        if ($base === '.DS_Store' || $base === 'Thumbs.db' || str_ends_with($base, '~') || str_ends_with($base, '.swp')) {
            return true;
        }
        // Skriveni fajlovi osim .htaccess (npr. .gitkeep, .env).
        if ($base[0] === '.' && $base !== '.htaccess') {
            return true;
        }
        return preg_match('#^install/rw-proba-|/\.git/#', $rel) === 1;
    }

    private static function contents(string $root, string $rel): string
    {
        $path = $root . '/' . $rel;
        if ($rel === 'storage/.htaccess' && !is_file($path)) {
            $path = $root . '/install/templates/storage.htaccess';
        }
        $c = file_get_contents($path);
        if ($c === false) {
            throw new \RuntimeException('Ne mogu da pročitam ' . $rel);
        }
        return $c;
    }

    /**
     * Pravi ZIP sa MANIFEST.sha256. Vreme izmene svih unosa je fiksno (SOURCE_DATE_EPOCH ili 2026-01-01), da bi isti
     * izvor dao isti ZIP.
     * @return array{file:string,sha256:string,size:int,count:int}
     */
    public static function buildZip(string $root, string $outZip): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Build zahteva PHP ekstenziju zip.');
        }
        $version = self::version($root);
        $files = self::files($root);
        $manifest = '# racunator-panel ' . $version . " — SHA-256 svakog fajla paketa\n";
        $data = [];
        foreach ($files as $rel) {
            $data[$rel] = self::contents($root, $rel);
            $manifest .= hash('sha256', $data[$rel]) . '  ' . $rel . "\n";
        }
        $data['MANIFEST.sha256'] = $manifest;
        ksort($data, SORT_STRING);

        if (!is_dir(dirname($outZip))) {
            mkdir(dirname($outZip), 0755, true);
        }
        @unlink($outZip);
        $zip = new \ZipArchive();
        if ($zip->open($outZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Ne mogu da napravim ' . $outZip);
        }
        $mtime = (int) (getenv('SOURCE_DATE_EPOCH') ?: 1767225600);
        foreach ($data as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setCompressionName($name, \ZipArchive::CM_DEFLATE);
            if (method_exists($zip, 'setMtimeName')) {
                $zip->setMtimeName($name, $mtime);
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Zatvaranje ZIP-a nije uspelo.');
        }
        return ['file' => $outZip, 'sha256' => (string) hash_file('sha256', $outZip), 'size' => (int) filesize($outZip), 'count' => count($data)];
    }

    /** Pravi samoraspakujući install fajl od ZIP-a. */
    public static function buildInstaller(string $root, string $zipFile, string $outPhp): array
    {
        $version = self::version($root);
        $tpl = (string) file_get_contents($root . '/tools/stub/installer-stub.php');
        $zip = (string) file_get_contents($zipFile);
        if (!str_ends_with(rtrim($tpl), '__halt_compiler();')) {
            throw new \RuntimeException('Šablon stuba mora da se završava sa __halt_compiler();');
        }
        $stub = strtr(rtrim($tpl), ['@@VERSION@@' => $version, '@@SHA256@@' => hash('sha256', $zip)]);
        $out = $stub . "\n" . chunk_split(base64_encode($zip), 76, "\n");
        if (file_put_contents($outPhp, $out) === false) {
            throw new \RuntimeException('Ne mogu da upišem ' . $outPhp);
        }
        return ['file' => $outPhp, 'sha256' => (string) hash_file('sha256', $outPhp), 'size' => (int) filesize($outPhp)];
    }

    /** SHA256SUMS u formatu `sha256sum` (dva razmaka). @param list<string> $files */
    public static function writeSums(array $files, string $out): void
    {
        $lines = '';
        foreach ($files as $f) {
            $lines .= hash_file('sha256', $f) . '  ' . basename($f) . "\n";
        }
        file_put_contents($out, $lines);
    }
}
