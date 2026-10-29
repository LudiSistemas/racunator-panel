<?php
declare(strict_types=1);

namespace RacunatorPanel\Install;

use RacunatorPanel\Db\Migrator;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Services\AppFiles;
use RacunatorPanel\Services\Backup;
use RacunatorPanel\Services\HttpClient;
use RacunatorPanel\Services\Maintenance;
use RacunatorPanel\Services\NativeHttpClient;
use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Services\ReleaseInfo;

/**
 * Ažuriranje i povratak (plan §5a). Isti kod koriste panel (/azuriranje, paket sa GitHub-a) i install fajl nad postojećom
 * instalacijom (paket već raspakovan u releases/<v>/, bez mreže).
 *
 * Tok: preduslovi → maintenance.flag → rezervna kopija → paket (preuzimanje + SHA-256 + Ed25519, ili lokalni) →
 * releases/<v>/ → zamena direktorijuma (trenutni u _old/) → migracije → settings.version → /health → kraj održavanja.
 * Greška posle zamene → automatski povratak: _old/ nazad, baza iz kopije ako su migracije krenule.
 * Svaki korak ide u storage/logs/update.log; rezultat poslednjeg pokretanja u storage/logs/update-last.json.
 *
 * Klasa nije final: testovi nasleđivanjem podmeću migracije i samoproveru koje padaju.
 */
class Updater
{
    public const LOG = 'logs/update.log';
    public const LAST = 'logs/update-last.json';
    public const LOCK = 'update.lock';
    public const DEFAULT_PACKAGE_BYTES = 8 * 1024 * 1024;

    protected readonly string $storage;
    /** @var list<array{at:string,step:string,status:string,message:string}> */
    private array $steps = [];
    /** @var resource|null */
    private $lockHandle = null;
    /** Stanje za povratak ako proces umre usred posla (shutdown). */
    private ?array $inFlight = null;

    public function __construct(
        protected readonly string $root,
        protected readonly \PDO $pdo,
        protected readonly SettingsRepository $settings,
        protected readonly AuditLogRepository $audit,
        protected readonly ?HttpClient $http = null,
        protected readonly string $publicUrl = '',
        ?string $storage = null,
    ) {
        $this->storage = rtrim($storage ?? $root . '/storage', '/');
    }

    public function currentVersion(): string
    {
        return $this->settings->get('version') ?? Installer::appVersion($this->root);
    }

    public function backups(): Backup
    {
        return new Backup($this->root, $this->pdo, $this->storage, $this->ownedTables());
    }

    /** Rezultat poslednjeg ažuriranja/povratka (za prikaz posle preusmerenja). */
    public function lastResult(): ?array
    {
        $f = $this->storage . '/' . self::LAST;
        $data = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Šta sprečava ažuriranje (prazna lista = može). Proverava se pre ikakve izmene.
     * @return list<string>
     */
    public function preflight(?int $packageBytes = null, bool $needsNetwork = false): array
    {
        $p = AppFiles::swapProblems($this->root);
        foreach (['', '/backups', '/tmp', '/logs'] as $d) {
            $dir = $this->storage . $d;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $p[] = 'Folder storage' . $d . '/ nije upisiv.';
            }
        }
        if (!class_exists(\ZipArchive::class)) {
            $p[] = 'PHP ekstenzija „zip" nije uključena — potrebna je za rezervnu kopiju i raspakivanje. Zatražite je od hostinga.';
        }
        if ($needsNetwork) {
            if ($this->http === null || ($this->http instanceof NativeHttpClient && !NativeHttpClient::available())) {
                $p[] = 'Hosting ne dozvoljava odlazne HTTP pozive (nema curl, allow_url_fopen isključen). Koristite ručno ažuriranje install fajlom.';
            }
        }
        $need = 3 * ($packageBytes ?? self::DEFAULT_PACKAGE_BYTES);
        $free = AppFiles::freeSpace($this->root);
        if ($free !== null && $free < $need) {
            $p[] = 'Nema dovoljno prostora na disku: potrebno ' . self::mb($need) . ', slobodno ' . self::mb($free) . '.';
        }
        return $p;
    }

    /** Ažuriranje na izdanje sa GitHub-a (poziva se tek posle potvrde administratora). */
    public function updateFromRelease(ReleaseInfo $release, ?int $userId, string $ip): array
    {
        $from = $this->currentVersion();
        return $this->run('azuriranje', $from, $release->version, $userId, $ip, $release->zipSize, true,
            fn (): string => $this->fetchRelease($release, $userId, $ip));
    }

    /** Ručno ažuriranje iz već raspakovanog paketa (install fajl nad postojećom instalacijom). */
    public function updateFromDirectory(string $dir, ?int $userId, string $ip): array
    {
        $from = $this->currentVersion();
        $to = AppFiles::versionOf($dir) ?? '?';
        return $this->run('rucno', $from, $to, $userId, $ip, AppFiles::treeSize($dir), false,
            function () use ($dir): string {
                $errors = AppFiles::verifyManifest($dir);
                if ($errors !== []) {
                    throw new \RuntimeException('Paket nije ispravan: ' . implode('; ', array_slice($errors, 0, 5)));
                }
                $this->step('paket', 'ok', 'Lokalni paket ' . basename($dir) . ' je proveren (SHA-256 svih fajlova).');
                return $dir;
            });
    }

    /**
     * Zajednički tok ažuriranja.
     * @param \Closure():string $obtain vraća direktorijum sa raspakovanim i proverenim paketom
     */
    private function run(string $kind, string $from, string $to, ?int $userId, string $ip, ?int $bytes, bool $network, \Closure $obtain): array
    {
        $this->steps = [];
        $result = ['ok' => false, 'kind' => $kind, 'from' => $from, 'to' => $to, 'backup' => null, 'error' => null,
            'rolled_back' => false, 'started_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->step('pocetak', 'info', ($kind === 'rucno' ? 'Ručno ažuriranje' : 'Ažuriranje') . ' ' . $from . ' → ' . $to . '.');

        if (ReleaseChecker::versionFromTag($to) === null) {
            return $this->finish($result, 'Paket nema ispravnu verziju (src/version.php).');
        }
        if (version_compare($to, $from, '<')) {
            return $this->finish($result, 'Paket ' . $to . ' je stariji od instalirane verzije ' . $from . '. Za vraćanje koristite „Vrati prethodnu verziju".');
        }
        if ($kind !== 'rucno' && !version_compare($to, $from, '>')) {
            return $this->finish($result, 'Verzija ' . $to . ' je već instalirana.');
        }
        if (!$this->acquireLock()) {
            return $this->finish($result, 'Ažuriranje je već u toku (drugi prozor ili korisnik). Sačekajte nekoliko minuta.', false);
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        $problems = $this->preflight($bytes, $network);
        if ($problems !== []) {
            foreach ($problems as $pr) {
                $this->step('preduslovi', 'fail', $pr);
            }
            return $this->finish($result, 'Preduslovi nisu ispunjeni — ništa nije promenjeno.');
        }
        $this->step('preduslovi', 'ok', 'Prava pisanja, prostor i ekstenzije su u redu.');

        $baseline = $this->probeHealth();
        $swap = null;
        $sourceDir = null;
        $migrationsStarted = false;
        $tablesBefore = [];
        try {
            Maintenance::enable($this->storage, 'azuriranje ' . $from . ' → ' . $to);
            $this->step('odrzavanje', 'ok', 'Uključen režim održavanja (aplikacija dobija „pokušaj kasnije").');

            $meta = $this->backups()->create($from, 'pre ažuriranja na ' . $to);
            $result['backup'] = $meta['name'];
            $this->step('rezervna_kopija', 'ok', 'Rezervna kopija ' . $meta['name'] . ' (' . self::mb($meta['size']) . ', fajlovi + baza).');

            $sourceDir = $obtain();
            $actual = AppFiles::versionOf($sourceDir);
            if ($actual !== $to) {
                throw new \RuntimeException('Paket sadrži verziju ' . ($actual ?? '?') . ' umesto ' . $to . '.');
            }

            $this->inFlight = ['source' => $sourceDir, 'journal' => [], 'backup' => $meta['name'], 'migrations' => false];
            $swap = AppFiles::swapIn($this->root, $sourceDir);
            $this->inFlight['journal'] = $swap;
            $this->step('zamena', 'ok', 'Fajlovi zamenjeni (prethodna verzija je u ' . AppFiles::OLD_DIR . '/).');
            $this->afterSwap($sourceDir, $to);

            $tablesBefore = Backup::listTables($this->pdo);
            $migrationsStarted = true;
            $this->inFlight['migrations'] = true;
            $applied = $this->runMigrations();
            $this->rememberNewTables($tablesBefore);
            $this->step('migracije', 'ok', $applied === [] ? 'Baza je već bila ažurna.' : 'Primenjene migracije: ' . implode(', ', $applied) . '.');

            $this->settings->set('version', $to);
            $this->step('verzija', 'ok', 'Upisana verzija ' . $to . '.');

            Maintenance::disable($this->storage);
            $this->checkAfter($baseline, $to);
        } catch (\Throwable $e) {
            $this->step('greska', 'fail', $e->getMessage());
            $result['rolled_back'] = $this->rollback($swap, $sourceDir, $migrationsStarted, $result['backup']);
            $this->inFlight = null;
            Maintenance::disable($this->storage);
            $this->safeAudit($userId, 'update.failed', $ip, ['from' => $from, 'to' => $to, 'error' => $e->getMessage(), 'rolled_back' => $result['rolled_back']]);
            return $this->finish($result, $e->getMessage());
        }
        $this->inFlight = null;
        $this->cleanup($sourceDir, $to);
        $this->step('kraj', 'ok', 'Ažuriranje na ' . $to . ' je završeno.');
        $result['ok'] = true;
        $this->safeAudit($userId, 'update.run', $ip, ['from' => $from, 'to' => $to, 'backup' => $result['backup'], 'kind' => $kind]);
        return $this->finish($result, null);
    }

    /**
     * „Vrati prethodnu verziju": fajlovi i baza iz rezervne kopije. Podaci primljeni posle te kopije se gube
     * (zato panel pre potvrde nudi CSV izvoz).
     */
    public function revert(string $backupName, ?int $userId, string $ip): array
    {
        $this->steps = [];
        $from = $this->currentVersion();
        $backup = $this->backups();
        $meta = $backup->find($backupName);
        $result = ['ok' => false, 'kind' => 'povratak', 'from' => $from, 'to' => $meta['version'] ?? '?', 'backup' => $backupName,
            'error' => null, 'rolled_back' => false, 'started_at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->step('pocetak', 'info', 'Povratak na kopiju ' . $backupName . ' (verzija ' . $result['to'] . ').');
        if ($meta === null) {
            return $this->finish($result, 'Rezervna kopija ne postoji.');
        }
        if (!$this->acquireLock()) {
            return $this->finish($result, 'Ažuriranje ili povratak je već u toku.', false);
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $problems = $this->preflight(AppFiles::treeSize($backup->dir() . '/' . $backupName));
        if ($problems !== []) {
            foreach ($problems as $pr) {
                $this->step('preduslovi', 'fail', $pr);
            }
            return $this->finish($result, 'Preduslovi nisu ispunjeni — ništa nije promenjeno.');
        }
        $src = $this->root . '/' . AppFiles::RELEASES_DIR . '/vracanje-' . $backupName;
        $journal = null;
        $dropAlso = array_values(array_diff($this->ownedTables() ?? [], (array) $meta['tables']));
        try {
            Maintenance::enable($this->storage, 'povratak na ' . $backupName);
            $this->step('odrzavanje', 'ok', 'Uključen režim održavanja.');
            if (file_exists($src)) {
                AppFiles::removeTree($src);
            }
            AppFiles::extractZip($backup->dir() . '/' . $backupName . '/' . Backup::FILES_ZIP, $src);
            $journal = AppFiles::swapIn($this->root, $src);
            $this->step('fajlovi', 'ok', 'Vraćeni fajlovi verzije ' . $result['to'] . '.');
            $backup->restoreDatabase($backupName, $dropAlso);
            $this->step('baza', 'ok', 'Vraćena baza iz kopije.');
        } catch (\Throwable $e) {
            $this->step('greska', 'fail', $e->getMessage());
            if ($journal !== null) {
                try {
                    AppFiles::undoSwap($this->root, $src, $journal);
                    $this->step('povratak', 'warn', 'Fajlovi su vraćeni na stanje pre pokušaja. Baza možda nije potpuno vraćena — proverite panel.');
                } catch (\Throwable $e2) {
                    $this->step('povratak', 'fail', $e2->getMessage());
                }
            }
            Maintenance::disable($this->storage);
            try {
                AppFiles::removeTree($src);
            } catch (\Throwable) {
            }
            $this->safeAudit($userId, 'update.revert_failed', $ip, ['backup' => $backupName, 'error' => $e->getMessage()]);
            return $this->finish($result, $e->getMessage());
        }
        Maintenance::disable($this->storage);
        try {
            AppFiles::removeTree($src);
        } catch (\Throwable) {
        }
        $this->step('kraj', 'ok', 'Vraćena verzija ' . ($this->settings->get('version') ?? $result['to']) . '.');
        $result['ok'] = true;
        $this->safeAudit($userId, 'update.revert', $ip, ['from' => $from, 'to' => $result['to'], 'backup' => $backupName]);
        return $this->finish($result, null);
    }

    /** Migracije nove verzije (Migrator se učitava posle zamene, pa vidi nove fajlove migracija). @return list<string> */
    protected function runMigrations(): array
    {
        return (new Migrator($this->pdo))->migrate();
    }

    /** Samoprovera: 'ok' | 'nedostupno' | poruka o grešci. Tekuća verzija se upoređuje sa $expect ako je zadata. */
    protected function probeHealth(?string $expect = null): string
    {
        if ($this->http === null || $this->publicUrl === '') {
            return 'nedostupno';
        }
        try {
            $res = $this->http->get(rtrim($this->publicUrl, '/') . '/health', ['Accept' => 'application/json', 'User-Agent' => 'racunator-panel-azuriranje']);
        } catch (\Throwable) {
            return 'nedostupno';
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($json) || ($json['ok'] ?? false) !== true) {
            return '/health je vratio status ' . $res['status'];
        }
        if ($expect !== null && (string) ($json['version'] ?? '') !== $expect) {
            return '/health prijavljuje verziju ' . ($json['version'] ?? '?') . ' umesto ' . $expect;
        }
        return 'ok';
    }

    private function checkAfter(string $baseline, string $to): void
    {
        if ($baseline !== 'ok') {
            $this->step('samoprovera', 'warn', 'Samoprovera nije moguća (server ne može da pozove sam sebe). Otvorite ' . rtrim($this->publicUrl, '/') . '/health i proverite verziju.');
            return;
        }
        $after = 'nedostupno';
        for ($i = 0; $i < 3; $i++) {
            $after = $this->probeHealth($to);
            if ($after === 'ok') {
                break;
            }
            usleep(700000);
        }
        if ($after !== 'ok') {
            throw new \RuntimeException('Samoprovera nije prošla: ' . $after . '.');
        }
        $this->step('samoprovera', 'ok', '/health odgovara verzijom ' . $to . '.');
    }

    /** Preuzimanje i provera paketa sa GitHub-a; vraća releases/<v>/. */
    private function fetchRelease(ReleaseInfo $r, ?int $userId, string $ip): string
    {
        if ($this->http === null) {
            throw new \RuntimeException('Nema HTTP klijenta za preuzimanje.');
        }
        foreach (['zip' => $r->zipUrl, 'SHA256SUMS' => $r->sumsUrl] as $what => $url) {
            if ($url === null) {
                throw new \RuntimeException('Izdanje ' . $r->tag . ' nema ' . $what . ' među fajlovima — ažuriranje nije moguće.');
            }
        }
        foreach ([$r->zipUrl, $r->sumsUrl, $r->sigUrl] as $url) {
            if ($url !== null && !str_starts_with($url, ReleaseChecker::DOWNLOAD_PREFIX)) {
                throw new \RuntimeException('Adresa paketa nije na zvaničnom repou (' . $url . ').');
            }
        }
        $tmp = $this->storage . '/tmp/azuriranje-' . $r->version;
        if (file_exists($tmp)) {
            AppFiles::removeTree($tmp);
        }
        AppFiles::ensureDir($tmp, 0700);
        $zipFile = $tmp . '/' . $r->zipName();
        $zip = $this->download($r->zipUrl);
        file_put_contents($zipFile, $zip);
        $this->step('preuzimanje', 'ok', 'Preuzet ' . $r->zipName() . ' (' . self::mb(strlen($zip)) . ').');

        $sums = $this->download($r->sumsUrl);
        $expected = ReleaseChecker::sumFor($sums, $r->zipName());
        if ($expected === null || !hash_equals($expected, hash('sha256', $zip))) {
            throw new \RuntimeException('SHA-256 paketa se ne slaže sa SHA256SUMS — paket je oštećen ili podmetnut.');
        }
        $this->step('sha256', 'ok', 'SHA-256 paketa se slaže sa SHA256SUMS.');

        $sig = $r->sigUrl !== null ? $this->download($r->sigUrl) : null;
        $verified = $sig !== null ? ReleaseChecker::verifySignature($zip, $sig) : null;
        if ($verified === false) {
            throw new \RuntimeException('Digitalni potpis paketa nije ispravan — paket je odbijen.');
        }
        if ($verified === null) {
            $why = !function_exists('sodium_crypto_sign_verify_detached') ? 'hosting nema PHP ekstenziju sodium'
                : (!ReleaseChecker::publicKeyConfigured() ? 'javni ključ za potpis nije postavljen u ovoj verziji' : 'izdanje nema potpis');
            if ($sig === null && function_exists('sodium_crypto_sign_verify_detached') && ReleaseChecker::publicKeyConfigured()) {
                throw new \RuntimeException('Izdanje nema digitalni potpis (.sig) — paket je odbijen.');
            }
            $this->step('potpis', 'warn', 'Potpis nije proveren (' . $why . '); oslanjamo se samo na SHA-256.');
            $this->safeAudit($userId, 'update.warning', $ip, ['to' => $r->version, 'warning' => 'bez provere potpisa: ' . $why]);
        } else {
            $this->step('potpis', 'ok', 'Digitalni potpis (Ed25519) je ispravan.');
        }

        $dest = $this->root . '/' . AppFiles::RELEASES_DIR . '/' . $r->version;
        if (file_exists($dest)) {
            AppFiles::removeTree($dest);
        }
        AppFiles::extractZip($zipFile, $dest);
        $errors = AppFiles::verifyManifest($dest);
        if ($errors !== []) {
            throw new \RuntimeException('Raspakovan paket nije ispravan: ' . implode('; ', array_slice($errors, 0, 5)));
        }
        $this->step('raspakivanje', 'ok', 'Paket raspakovan u ' . AppFiles::RELEASES_DIR . '/' . $r->version . '/ i proveren fajl po fajl.');
        return $dest;
    }

    private function download(string $url): string
    {
        $res = $this->http->get($url, ['User-Agent' => 'racunator-panel/' . $this->currentVersion(), 'Accept' => 'application/octet-stream']);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Preuzimanje ' . basename((string) parse_url($url, PHP_URL_PATH)) . ' nije uspelo (status ' . $res['status'] . ').');
        }
        return $res['body'];
    }

    /** Posle zamene: korisnikov .htaccess u korenu se ne dira; ako ga nema, postavlja se iz paketa. storage/.htaccess mora da postoji. */
    private function afterSwap(string $sourceDir, string $to): void
    {
        $new = $sourceDir . '/.htaccess';
        $cur = $this->root . '/.htaccess';
        if (is_file($new)) {
            if (!is_file($cur)) {
                @copy($new, $cur);
                $this->step('htaccess', 'ok', 'Postavljen .htaccess u korenu.');
            } elseif (hash_file('sha256', $new) !== hash_file('sha256', $cur)) {
                @copy($new, $this->root . '/.htaccess.nova-' . $to);
                $this->step('htaccess', 'info', 'Vaš .htaccess u korenu je zadržan; nova verzija predlaže .htaccess.nova-' . $to . ' (uporedite ručno).');
            }
        }
        $tpl = $this->root . '/install/templates/storage.htaccess';
        if (!is_file($this->storage . '/.htaccess') && is_file($tpl)) {
            @copy($tpl, $this->storage . '/.htaccess');
        }
    }

    /**
     * @param list<array{0:string,1:string}>|null $swap
     * @return bool da li je stanje vraćeno
     */
    private function rollback(?array $swap, ?string $sourceDir, bool $migrationsStarted, ?string $backupName): bool
    {
        $ok = true;
        if ($swap !== null && $sourceDir !== null) {
            try {
                Maintenance::enable($this->storage, 'povratak posle neuspelog ažuriranja');
                AppFiles::undoSwap($this->root, $sourceDir, $swap);
                $this->step('povratak_fajlova', 'ok', 'Vraćeni fajlovi prethodne verzije.');
            } catch (\Throwable $e) {
                $ok = false;
                $this->step('povratak_fajlova', 'fail', $e->getMessage());
            }
        }
        if ($migrationsStarted && $backupName !== null) {
            try {
                $meta = $this->backups()->find($backupName);
                $drop = array_values(array_diff($this->ownedTables() ?? [], (array) ($meta['tables'] ?? [])));
                $this->backups()->restoreDatabase($backupName, $drop);
                $this->step('povratak_baze', 'ok', 'Baza vraćena iz kopije ' . $backupName . '.');
            } catch (\Throwable $e) {
                $ok = false;
                $this->step('povratak_baze', 'fail', 'Baza nije vraćena: ' . $e->getMessage() . ' Kopija je u storage/backups/' . $backupName . '/.');
            }
        }
        if ($sourceDir !== null && is_dir($sourceDir)) {
            try {
                AppFiles::removeTree($sourceDir);
            } catch (\Throwable) {
            }
        }
        return $ok;
    }

    private function cleanup(?string $sourceDir, string $to): void
    {
        foreach ([$sourceDir, $this->storage . '/tmp/azuriranje-' . $to] as $dir) {
            if ($dir !== null && file_exists($dir)) {
                try {
                    AppFiles::removeTree($dir);
                } catch (\Throwable) {
                    $this->step('ciscenje', 'warn', 'Nije obrisan privremeni folder ' . $dir . '.');
                }
            }
        }
        $releases = $this->root . '/' . AppFiles::RELEASES_DIR;
        if (is_dir($releases) && count(scandir($releases) ?: []) <= 2) {
            @rmdir($releases);
        }
        $stub = (new Installer($this->root))->removeStub();
        if ($stub !== null) {
            $this->step('ciscenje', 'ok', 'Obrisan install fajl ' . $stub . '.');
        }
        @unlink($this->storage . '/tmp/manual-update.json');
        try {
            $this->backups()->prune();
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $before */
    private function rememberNewTables(array $before): void
    {
        $owned = $this->ownedTables() ?? [];
        $new = array_diff(Backup::listTables($this->pdo), $before);
        if ($new !== []) {
            $all = array_values(array_unique(array_merge($owned, $new)));
            sort($all);
            $this->settings->set('owned_tables', json_encode($all));
        }
    }

    /** @return list<string>|null tabele panela (null = nepoznato → sve tabele baze) */
    protected function ownedTables(): ?array
    {
        $v = $this->settings->get('owned_tables');
        $list = $v !== null ? json_decode($v, true) : null;
        return is_array($list) && $list !== [] ? array_values(array_map('strval', $list)) : null;
    }

    private function acquireLock(): bool
    {
        AppFiles::ensureDir($this->storage);
        $fp = @fopen($this->storage . '/' . self::LOCK, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $this->lockHandle = $fp;
        register_shutdown_function(function (): void {
            $this->onShutdown();
        });
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** Proces je prekinut usred posla (fatalna greška, isteklo vreme): pokušaj povratka. */
    private function onShutdown(): void
    {
        if ($this->inFlight === null) {
            return;
        }
        $f = $this->inFlight;
        $this->inFlight = null;
        @set_time_limit(120);
        $this->step('prekid', 'fail', 'Proces je prekinut usred ažuriranja — pokušava se automatski povratak.');
        $this->rollback($f['journal'] !== [] ? $f['journal'] : null, $f['source'], (bool) $f['migrations'], $f['backup']);
        Maintenance::disable($this->storage);
        $this->writeLast(['ok' => false, 'kind' => 'azuriranje', 'error' => 'Proces je prekinut.', 'rolled_back' => true, 'steps' => $this->steps]);
        $this->releaseLock();
    }

    private function finish(array $result, ?string $error, bool $release = true): array
    {
        $result['error'] = $error;
        $result['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        if ($error !== null) {
            $this->step('kraj', $result['rolled_back'] ? 'warn' : 'fail',
                $result['rolled_back'] ? 'Ažuriranje nije uspelo; vraćena je prethodna verzija.' : 'Prekinuto: ' . $error);
        }
        $result['steps'] = $this->steps;
        $this->writeLast($result);
        if ($release) {
            $this->releaseLock();
        }
        return $result;
    }

    private function writeLast(array $result): void
    {
        $result['steps'] ??= $this->steps;
        @file_put_contents($this->storage . '/' . self::LAST, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function step(string $step, string $status, string $message): void
    {
        $at = gmdate('Y-m-d\TH:i:s\Z');
        $this->steps[] = ['at' => $at, 'step' => $step, 'status' => $status, 'message' => $message];
        $dir = $this->storage . '/logs';
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($dir . '/update.log', '[' . $at . '] [' . $status . '] ' . $step . ': ' . $message . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function safeAudit(?int $userId, string $action, string $ip, array $details): void
    {
        try {
            $this->audit->log($userId, $action, null, null, $ip, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);
        } catch (\Throwable) {
            // Dnevnik radnji ne sme da obori ažuriranje; korak je ionako u update.log.
        }
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
