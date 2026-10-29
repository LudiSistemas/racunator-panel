<?php
declare(strict_types=1);

/*
 * Instalacija i održavanje iz komandne linije (SSH, Docker test, document root = public/).
 *
 *   php install/cli.php provera
 *   php install/cli.php instaliraj --db=sqlite --url=https://domen.rs/panel --admin=marko --naziv="Knjigovodstvo XY"
 *   php install/cli.php instaliraj --db=mysql --db-host=localhost --db-port=3306 --db-name=panel --db-user=panel \
 *         --url=https://domen.rs/panel --admin=marko --naziv="Knjigovodstvo XY"
 *       lozinke: env RACUNATOR_DB_PASS i RACUNATOR_ADMIN_PASSWORD (ili --db-pass= / --password=, vidljivo u istoriji ljuske)
 *       --storage=/apsolutna/putanja — folder za podatke van web root-a (podrazumevano storage/ u korenu)
 *   php install/cli.php kopija                      — nova rezervna kopija (fajlovi + baza)
 *   php install/cli.php kopije                      — spisak rezervnih kopija
 *   php install/cli.php vrati --kopija=20261029-120000-1-<sufiks> [--samo-baza]
 *   php install/cli.php azuriraj                     — ručno ažuriranje iz paketa koji je ostavio install fajl
 *         (storage/tmp/manual-update/); potpis se proverava javnim ključem ove instalacije
 *   php install/cli.php azuriraj --paket=racunator-panel-1.2.3.zip [--potpis=racunator-panel-1.2.3.zip.sig]
 *         — ručno ažuriranje iz ZIP-a sa stranice izdanja (potpis je obavezan); bez ekstenzije sodium potpis ne može
 *         da se proveri, pa je potreban i --sha256-provereno (uporedili ste SHA-256 ZIP-a sa SHA256SUMS izdanja)
 *   php install/cli.php dozvola                      — adresa čarobnjaka sa novim ključem (nova instalacija preko pregledača)
 *
 * Izlazni kod: 0 uspeh, 1 greška, 2 pogrešni argumenti.
 */

use RacunatorPanel\Db\Connection;
use RacunatorPanel\Install\Checks;
use RacunatorPanel\Install\Installer;
use RacunatorPanel\Install\Updater;
use RacunatorPanel\Services\NativeHttpClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$args = $argv;
array_shift($args);
$cmd = 'instaliraj';
if ($args !== [] && !str_starts_with($args[0], '--')) {
    $cmd = (string) array_shift($args);
}
$opt = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/s', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    } else {
        fwrite(STDERR, "Nepoznat argument: $a\n");
        exit(2);
    }
}
$root = dirname(__DIR__);
$installer = new Installer($root);
if (($nested = Installer::nestedCopyReason($root)) !== null) {
    fwrite(STDERR, 'GREŠKA: ' . $nested . " Pokrenite install/cli.php iz korena panela.\n");
    exit(1);
}
$out = static function (string $s): void {
    fwrite(STDOUT, $s . "\n");
};
$fail = static function (string $s, int $code = 1): never {
    fwrite(STDERR, 'GREŠKA: ' . $s . "\n");
    exit($code);
};

try {
    switch ($cmd) {
        case 'provera':
            $results = (new Checks($root, $opt['install-url'] ?? null, str_starts_with((string) ($opt['url'] ?? ''), 'https://'), null, $opt['storage'] ?? null))->run();
            foreach ($results as $r) {
                $out(sprintf('[%-10s] %s: %s%s', $r['status'], $r['label'], $r['message'], $r['hint'] !== '' && $r['status'] !== Checks::OK ? ' → ' . $r['hint'] : ''));
            }
            exit(Checks::overall($results) === Checks::FAIL ? 1 : 0);

        case 'dozvola':
            if ($installer->isInstalled()) {
                $fail('Panel je već instaliran (postoji config.php).');
            }
            $token = $installer->issueToken();
            $url = rtrim((string) ($opt['url'] ?? ''), '/');
            $out('Ključ za čarobnjak je izdat (prethodni, ako je postojao, više ne važi). Otvorite u pregledaču:');
            $out(($url !== '' ? $url : 'https://vas-domen.rs/folder-panela') . '/install/?t=' . $token);
            exit(0);

        case 'instaliraj':
            foreach (['url', 'admin', 'naziv'] as $req) {
                if (!isset($opt[$req])) {
                    $fail("Nedostaje --$req=…", 2);
                }
            }
            $password = (string) (getenv('RACUNATOR_ADMIN_PASSWORD') ?: ($opt['password'] ?? ''));
            if ($password === '') {
                $fail('Lozinka admina: env RACUNATOR_ADMIN_PASSWORD ili --password=', 2);
            }
            $storage = isset($opt['storage']) ? $installer->normalizeStorage((string) $opt['storage']) : $installer->bootStorage();
            $results = (new Checks($root, null, str_starts_with((string) $opt['url'], 'https://'), null, $storage))->run();
            foreach ($results as $r) {
                if ($r['status'] === Checks::FAIL && $r['key'] !== 'rewrite') {
                    $fail($r['label'] . ': ' . $r['message']);
                }
            }
            $db = $installer->dbConfig([
                'driver' => $opt['db'] ?? 'sqlite',
                'host' => $opt['db-host'] ?? 'localhost',
                'port' => $opt['db-port'] ?? '',
                'name' => $opt['db-name'] ?? '',
                'user' => $opt['db-user'] ?? '',
                'pass' => (string) (getenv('RACUNATOR_DB_PASS') ?: ($opt['db-pass'] ?? '')),
            ], $storage);
            $problem = $installer->testDatabase($db, $storage);
            if ($problem !== null) {
                $fail($problem);
            }
            $res = $installer->install([
                'db' => $db,
                'storage' => $storage,
                'public_url' => (string) $opt['url'],
                'admin_username' => (string) $opt['admin'],
                'admin_password' => $password,
                'accountant_name' => (string) $opt['naziv'],
                'ip' => '127.0.0.1',
            ]);
            $out('Instalirano: verzija ' . $res['version'] . ', migracije: ' . (implode(', ', $res['migrations']) ?: '-'));
            $stub = $installer->removeStub();
            if ($stub !== null) {
                $out('Obrisan install fajl ' . $stub);
            }
            if (!isset($opt['bez-provere'])) {
                $h = Installer::checkHealth(new NativeHttpClient(10, 65536), $res['public_url'], (string) ($installer->loadConfig()['app_key'] ?? ''));
                $out(($h['ok'] ? 'OK: ' : 'UPOZORENJE: ') . $h['message']);
            }
            exit(0);

        case 'kopija':
        case 'kopije':
        case 'vrati':
        case 'azuriraj':
            if (!$installer->isInstalled()) {
                $fail('Panel nije instaliran (nema config.php).');
            }
            $cfg = $installer->loadConfig();
            $pdo = Connection::open((array) $cfg['db']);
            $repos = $installer->repositories($pdo);
            $updater = new Updater($root, $pdo, $repos['settings'], $repos['audit'], new NativeHttpClient(20, 65536),
                (string) ($cfg['public_url'] ?? ''), (string) ($cfg['storage'] ?? $root . '/storage'), null, (string) ($cfg['app_key'] ?? ''));
            if ($cmd === 'kopija') {
                $meta = $updater->backups()->create($updater->currentVersion(), 'ručno (CLI)');
                $out('Napravljena kopija ' . $meta['name'] . ' (' . $meta['size'] . ' B, tabele: ' . implode(', ', $meta['tables']) . ')');
                exit(0);
            }
            if ($cmd === 'kopije') {
                foreach ($updater->backups()->list() as $b) {
                    $out($b['name'] . "\t" . $b['version'] . "\t" . $b['size'] . " B\t" . $b['reason']);
                }
                exit(0);
            }
            if ($cmd === 'vrati') {
                $name = (string) ($opt['kopija'] ?? '');
                if (isset($opt['samo-baza'])) {
                    $updater->backups()->restoreDatabase($name);
                    $out('Baza vraćena iz kopije ' . $name);
                    exit(0);
                }
                $res = $updater->revert($name, null, '127.0.0.1');
            } else {
                $storage = rtrim((string) ($cfg['storage'] ?? $root . '/storage'), '/');
                $paket = (string) ($opt['paket'] ?? '');
                $version = null;
                if ($paket === '' || is_dir(str_starts_with($paket, '/') ? $paket : $root . '/' . $paket)) {
                    // Paket koji je install fajl ostavio (stari oblik --paket=releases/X.Y.Z vodi na isti paket).
                    $pending = is_file($storage . '/tmp/manual-update.json') ? json_decode((string) file_get_contents($storage . '/tmp/manual-update.json'), true) : null;
                    $version = is_array($pending) ? (string) ($pending['version'] ?? '') : '';
                    if (\RacunatorPanel\Services\ReleaseChecker::versionFromTag($version) !== $version || $version === '') {
                        $fail('Nema paketa koji čeka ažuriranje. Pokrenite prvo php racunator-panel-install.php, ili navedite --paket=<zip>.');
                    }
                    $zip = $storage . '/tmp/manual-update/racunator-panel-' . $version . '.zip';
                    $sig = $zip . '.sig';
                } else {
                    $zip = str_starts_with($paket, '/') ? $paket : getcwd() . '/' . $paket;
                    $sig = (string) ($opt['potpis'] ?? $zip . '.sig');
                    $sig = str_starts_with($sig, '/') ? $sig : getcwd() . '/' . $sig;
                }
                if (!is_file($zip)) {
                    $fail('Paket ne postoji: ' . $zip);
                }
                if (!$updater->canVerifySignatures() && !isset($opt['sha256-provereno'])) {
                    $fail('Potpis ne može da se proveri (nema ekstenzije sodium). Uporedite SHA-256 paketa (' . hash_file('sha256', $zip)
                        . ') sa SHA256SUMS na stranici izdanja i ponovite sa --sha256-provereno.');
                }
                $res = $updater->updateFromPackage($zip, is_file($sig) ? $sig : null, null, '127.0.0.1', isset($opt['sha256-provereno']), $version);
            }
            foreach ($res['steps'] as $s) {
                $out(sprintf('[%s] %-16s %s', $s['status'], $s['step'], $s['message']));
            }
            exit($res['ok'] ? 0 : 1);

        default:
            $fail('Nepoznata komanda „' . $cmd . '" (provera, instaliraj, dozvola, kopija, kopije, vrati, azuriraj).', 2);
    }
} catch (\Throwable $e) {
    $fail($e->getMessage());
}
