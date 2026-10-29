<?php
declare(strict_types=1);

namespace RacunatorPanel\Tools;

/**
 * Pravljenje paketa izdanja (koriste ga tools/build-installer.php, tools/build-release.php i testovi).
 *   ZIP:  public/, src/, install/, docs/, LICENSE, README.md, CHANGELOG.md, htaccess.dist, storage/.htaccess + MANIFEST.sha256
 *   Stub: tools/stub/installer-stub.php + base64 ZIP posle __halt_compiler() (+ Ed25519 potpis ZIP-a, ako postoji)
 * Isti ZIP je i paket za ažuriranje iz panela (racunator-panel-<verzija>.zip).
 * Koreni .htaccess ide u paket kao htaccess.dist: na mesto .htaccess ga stavlja tek install fajl (nova instalacija) ili
 * ažuriranje (kad korisnik nema svoj). Tako raspakovan paket u releases/<verzija>/ nema aktivna rewrite pravila.
 */
final class Package
{
    public const DIRS = ['public', 'src', 'install', 'docs'];
    public const FILES = ['LICENSE', 'README.md', 'CHANGELOG.md', 'htaccess.dist', 'storage/.htaccess'];
    public const INSTALLER_NAME = 'racunator-panel-install.php';
    /** Fajl u paketu => izvor u repou (kad se razlikuju). */
    private const SOURCES = ['htaccess.dist' => '.htaccess', 'storage/.htaccess' => 'install/templates/storage.htaccess'];

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
            if ($f !== 'htaccess.dist' && is_file($root . '/' . $f)) {
                $out[] = $f;
            } elseif (isset(self::SOURCES[$f]) && is_file($root . '/' . self::SOURCES[$f])) {
                $out[] = $f; // pravi se iz drugog fajla repoa (vidi contents())
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
        if (isset(self::SOURCES[$rel]) && ($rel === 'htaccess.dist' || !is_file($path))) {
            $path = $root . '/' . self::SOURCES[$rel];
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

    /**
     * Pravi samoraspakujući install fajl od ZIP-a. Potpis ZIP-a (sadržaj .sig fajla, base64) se ugrađuje u install fajl,
     * pa ručno ažuriranje može da ga proveri javnim ključem instalirane verzije; bez potpisa install fajl radi samo za novu
     * instalaciju (postojeća instalacija ga odbija, osim kad hosting nema sodium — vidi docs/azuriranje.md).
     */
    public static function buildInstaller(string $root, string $zipFile, string $outPhp, ?string $signature = null): array
    {
        $version = self::version($root);
        $tpl = (string) file_get_contents($root . '/tools/stub/installer-stub.php');
        $zip = (string) file_get_contents($zipFile);
        if (!str_ends_with(rtrim($tpl), '__halt_compiler();')) {
            throw new \RuntimeException('Šablon stuba mora da se završava sa __halt_compiler();');
        }
        $sig = $signature !== null ? trim($signature) : '';
        if ($sig !== '' && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $sig) !== 1) {
            throw new \RuntimeException('Potpis nije base64.');
        }
        $stub = strtr(rtrim($tpl), ['@@VERSION@@' => $version, '@@SHA256@@' => hash('sha256', $zip), '@@SIGNATURE@@' => $sig]);
        $out = $stub . "\n" . chunk_split(base64_encode($zip), 76, "\n");
        if (file_put_contents($outPhp, $out) === false) {
            throw new \RuntimeException('Ne mogu da upišem ' . $outPhp);
        }
        return ['file' => $outPhp, 'sha256' => (string) hash_file('sha256', $outPhp), 'size' => (int) filesize($outPhp), 'signed' => $sig !== ''];
    }

    /**
     * Ed25519 tajni ključ iz base64 (64 bajta tajnog ključa ili 32 bajta seed-a, kako ga ispisuje generate-signing-key.php).
     */
    public static function secretKey(string $b64): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('Potpis zahteva PHP ekstenziju sodium.');
        }
        $raw = base64_decode(trim($b64), true);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Privatni ključ nije ispravan base64.');
        }
        if (strlen($raw) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $raw = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        }
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Privatni ključ nije Ed25519 ključ (očekuje se 32 ili 64 bajta posle base64).');
        }
        return $raw;
    }

    /** Potpisuje fajl (Ed25519) i upisuje <fajl>.sig (base64 potpisa). Vraća sadržaj potpisa (base64). */
    public static function signFile(string $file, string $secretKey): string
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new \RuntimeException('Ne mogu da pročitam ' . $file);
        }
        $sig = base64_encode(sodium_crypto_sign_detached($data, $secretKey));
        if (file_put_contents($file . '.sig', $sig . "\n") === false) {
            throw new \RuntimeException('Ne mogu da upišem ' . $file . '.sig');
        }
        return $sig;
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
