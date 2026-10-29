<?php
declare(strict_types=1);

namespace RacunatorPanel\Install;

use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Db\Connection;
use RacunatorPanel\Db\Migrator;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\AuditLogRepository;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Repositories\PdoUserRepository;
use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Repositories\UserRepository;
use RacunatorPanel\Services\AppFiles;
use RacunatorPanel\Services\Backup;
use RacunatorPanel\Services\HttpClient;

/**
 * Logika instalacije bez HTTP-a (koriste je čarobnjak install/index.php, install/cli.php i testovi).
 * Redosled: provera ulaza → storage/ → privremeni config → baza i migracije → prvi admin → podešavanja →
 * config.php (0600) → install_lock. config.php nastaje tek kad je baza spremna, pa prekinuta instalacija može da se ponovi.
 */
final class Installer
{
    public const MIN_PASSWORD = 12;
    public const STUB_INFO = 'tmp/installer-stub.json';
    public const TOKEN_FILE = 'tmp/install-token';

    /** @var \Closure(\PDO):array{settings:SettingsRepository,users:UserRepository,audit:AuditLogRepository} */
    private \Closure $repos;

    public function __construct(private readonly string $root, ?\Closure $repos = null)
    {
        $this->repos = $repos ?? static fn (\PDO $pdo): array => [
            'settings' => new PdoSettingsRepository($pdo),
            'users' => new PdoUserRepository($pdo),
            'audit' => new PdoAuditLogRepository($pdo),
        ];
    }

    public function root(): string
    {
        return $this->root;
    }

    public function configFile(): string
    {
        return $this->root . '/config.php';
    }

    public function storage(): string
    {
        return $this->root . '/storage';
    }

    /** Postoji config.php — instalacija je urađena (instaler se više ne pokreće kao nova instalacija). */
    public function isInstalled(): bool
    {
        return is_file($this->configFile());
    }

    /** @return array<string,mixed> */
    public function loadConfig(): array
    {
        $cfg = require $this->configFile();
        if (!is_array($cfg)) {
            throw new \RuntimeException('config.php nije ispravan.');
        }
        return $cfg;
    }

    /** @return array{settings:SettingsRepository,users:UserRepository,audit:AuditLogRepository} */
    public function repositories(\PDO $pdo): array
    {
        return ($this->repos)($pdo);
    }

    public static function appVersion(string $root): string
    {
        $file = $root . '/src/version.php';
        $v = is_file($file) ? require $file : null;
        return is_string($v) ? $v : '0.0.0';
    }

    /**
     * Config niz za bazu iz polja forme.
     * @param array<string,mixed> $in driver, host, port, name, user, pass
     * @return array{driver:string,dsn:string,user:string,pass:string}
     */
    public function dbConfig(array $in): array
    {
        $driver = (string) ($in['driver'] ?? 'mysql');
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'dsn' => 'sqlite:' . $this->storage() . '/panel.sqlite', 'user' => '', 'pass' => ''];
        }
        if ($driver !== 'mysql') {
            throw new \InvalidArgumentException('Nepoznata vrsta baze.');
        }
        $host = trim((string) ($in['host'] ?? 'localhost'));
        $port = trim((string) ($in['port'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]_]+$/', $host) !== 1) {
            throw new \InvalidArgumentException('Adresa servera baze nije ispravna.');
        }
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new \InvalidArgumentException('Port baze mora biti broj od 1 do 65535.');
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Ime baze nije ispravno (slova, brojevi, _ i -).');
        }
        $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
        return ['driver' => 'mysql', 'dsn' => $dsn, 'user' => (string) ($in['user'] ?? ''), 'pass' => (string) ($in['pass'] ?? '')];
    }

    /** „Testiraj vezu": null = u redu, inače poruka na srpskom (bez lozinke). */
    public function testDatabase(array $db): ?string
    {
        try {
            if ($db['driver'] === 'sqlite') {
                $this->ensureStorage();
            }
            $pdo = Connection::open($db);
            if ($db['driver'] === 'mysql') {
                // Prava za pravljenje tabela (bez njih migracije ne mogu).
                $pdo->exec('CREATE TABLE IF NOT EXISTS racunator_proba_veze (id INT)');
                $pdo->exec('DROP TABLE racunator_proba_veze');
            }
            return $this->existingInstallProblem($pdo);
        } catch (\Throwable $e) {
            return self::explainDbError($e);
        }
    }

    public static function explainDbError(\Throwable $e): string
    {
        $info = $e instanceof \PDOException ? ($e->errorInfo ?? []) : [];
        $code = (int) ($info[1] ?? 0);
        $msg = $e->getMessage();
        if ($code === 0 && preg_match('/\[(\d{4})\]/', $msg, $m)) {
            $code = (int) $m[1];
        }
        return match (true) {
            $code === 1045 => 'Pogrešno korisničko ime ili lozinka baze (MySQL 1045). Proverite podatke u kontrolnom panelu hostinga → „MySQL Databases".',
            $code === 1044, $code === 1142 => 'Korisnik baze nema prava nad ovom bazom. U „MySQL Databases" dodelite korisniku „ALL PRIVILEGES" za bazu.',
            $code === 1049 => 'Baza sa tim imenom ne postoji. Napravite je u kontrolnom panelu (na hostingu ime često ima prefiks naloga, npr. „nalog_panel").',
            $code === 2002, $code === 2003, $code === 2005 => 'Server baze nije dostupan na toj adresi/portu. Na shared hostingu je to najčešće „localhost".',
            str_contains($msg, 'could not find driver') => 'PHP nema drajver za ovu bazu (pdo_mysql/pdo_sqlite).',
            default => 'Veza sa bazom nije uspela: ' . $msg,
        };
    }

    /** Baza već ima završenu instalaciju ili naloge → poruka; inače null. */
    private function existingInstallProblem(\PDO $pdo): ?string
    {
        $tables = Backup::listTables($pdo);
        if (in_array('settings', $tables, true)) {
            $r = $this->repositories($pdo);
            if ($r['settings']->get('install_lock') === '1') {
                return 'Ova baza već sadrži instaliran Računator panel. Za novu instalaciju koristite praznu bazu; '
                    . 'za ažuriranje postojeće instalacije vratite njen config.php.';
            }
        }
        if (in_array('users', $tables, true)) {
            $r ??= $this->repositories($pdo);
            if ($r['users']->all() !== []) {
                return 'Baza već ima naloge panela iz ranijeg pokušaja instalacije. Koristite praznu bazu ili obrišite tabele panela.';
            }
        }
        return null;
    }

    /** Predlog javne adrese iz zahteva: folder iznad install/ (npr. https://domen.rs/panel). */
    public static function suggestPublicUrl(array $server): string
    {
        $https = (($server['HTTPS'] ?? '') !== '' && ($server['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-fA-F:]+\](:\d{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/install/index.php'));
        $base = rtrim(dirname(dirname($script)), '/');
        return ($https ? 'https' : 'http') . '://' . $host . ($base === '.' ? '' : $base);
    }

    /** Normalizovana javna adresa ili izuzetak sa porukom. */
    public static function normalizePublicUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Javna adresa mora početi sa https:// (ili http://) i sadržati domen.');
        }
        if (isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('Javna adresa ne sme imati „?", „#" ni korisnika/lozinku.');
        }
        if (preg_match('/[\s"\'<>\\\\]/', $url) === 1) {
            throw new \InvalidArgumentException('Javna adresa sadrži nedozvoljene znakove.');
        }
        return $url;
    }

    /**
     * Procena jačine lozinke (isto pravilo kao merač u čarobnjaku).
     * @return array{score:int,label:string,ok:bool,problems:list<string>}
     */
    public static function passwordStrength(string $password, string $username = ''): array
    {
        $len = mb_strlen($password, 'UTF-8');
        $problems = [];
        if ($len < self::MIN_PASSWORD) {
            $problems[] = 'Lozinka mora imati najmanje ' . self::MIN_PASSWORD . ' znakova.';
        }
        if (strlen($password) > 1024) {
            $problems[] = 'Lozinka je predugačka.';
        }
        $lower = $username !== '' ? mb_strtolower($username, 'UTF-8') : '';
        if ($lower !== '' && mb_strlen($lower) >= 3 && str_contains(mb_strtolower($password, 'UTF-8'), $lower)) {
            $problems[] = 'Lozinka ne sme da sadrži korisničko ime.';
        }
        $common = ['123456789012', 'password1234', 'lozinka12345', 'qwertyuiopas', 'racunator123', 'knjigovodja1'];
        if (in_array(mb_strtolower($password, 'UTF-8'), $common, true) || preg_match('/^(.)\1+$/u', $password) === 1) {
            $problems[] = 'Lozinka je previše predvidiva.';
        }
        $classes = (int) preg_match('/[a-zčćžšđ]/u', $password) + (int) preg_match('/[A-ZČĆŽŠĐ]/u', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^\p{L}\d]/u', $password);
        $score = 0;
        if ($len >= self::MIN_PASSWORD) {
            $score = 1 + ($classes >= 3 ? 1 : 0) + ($len >= 16 ? 1 : 0) + ($len >= 20 || ($len >= 14 && $classes === 4) ? 1 : 0);
        }
        if ($problems !== []) {
            $score = 0;
        }
        $labels = ['preslaba', 'slaba', 'dobra', 'jaka', 'vrlo jaka'];
        return ['score' => $score, 'label' => $labels[$score], 'ok' => $problems === [], 'problems' => $problems];
    }

    /** @return list<string> greške za prvi nalog */
    public static function validateAdmin(string $username, string $password, string $password2, string $accountantName): array
    {
        $errors = [];
        if (preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $username) !== 1) {
            $errors[] = 'Korisničko ime: 3–64 znaka, samo slova bez kvačica, brojevi, tačka, crtica i donja crta.';
        }
        $errors = array_merge($errors, self::passwordStrength($password, $username)['problems']);
        if ($password !== $password2) {
            $errors[] = 'Lozinke se ne poklapaju.';
        }
        $name = trim($accountantName);
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            $errors[] = 'Naziv knjigovodstva: 2–200 znakova.';
        }
        return $errors;
    }

    /** Pravi storage/{backups,tmp,logs} i storage/.htaccess (zabrana pristupa). */
    public function ensureStorage(): void
    {
        $s = $this->storage();
        foreach (['', '/backups', '/tmp', '/logs'] as $d) {
            AppFiles::ensureDir($s . $d, 0755);
        }
        $tpl = __DIR__ . '/templates/storage.htaccess';
        if (!is_file($s . '/.htaccess') && is_file($tpl)) {
            copy($tpl, $s . '/.htaccess');
        }
        if (!is_file($s . '/index.html')) {
            @file_put_contents($s . '/index.html', '');
        }
    }

    /**
     * Cela instalacija.
     * @param array{db:array,public_url:string,admin_username:string,admin_password:string,accountant_name:string,ip?:string} $in
     * @return array{version:string,public_url:string,migrations:list<string>,user_id:int}
     */
    public function install(array $in): array
    {
        if ($this->isInstalled()) {
            throw new \RuntimeException('Panel je već instaliran (postoji config.php). Instaler se ne može ponovo pokrenuti.');
        }
        $publicUrl = self::normalizePublicUrl((string) $in['public_url']);
        $errors = self::validateAdmin($in['admin_username'], $in['admin_password'], $in['admin_password'], $in['accountant_name']);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $db = $in['db'];
        $version = self::appVersion($this->root);

        $this->ensureStorage();
        $config = [
            'db' => ['driver' => $db['driver'], 'dsn' => $db['dsn'], 'user' => (string) $db['user'], 'pass' => (string) $db['pass']],
            'public_url' => $publicUrl,
            'app_key' => bin2hex(random_bytes(32)),
            'storage' => $this->storage(),
        ];
        // Privremeni config odmah: ako koren nije upisiv, greška je pre bilo kakve izmene baze.
        $tmp = $this->configFile() . '.instalacija';
        if (@file_put_contents($tmp, self::configContents($config), LOCK_EX) === false) {
            throw new \RuntimeException('Ne mogu da upišem config.php u ' . $this->root . ' — proverite prava pisanja.');
        }
        @chmod($tmp, 0600);

        $sqliteFile = $db['driver'] === 'sqlite' ? substr($db['dsn'], 7) : null;
        $sqliteExisted = $sqliteFile !== null && is_file($sqliteFile);
        try {
            $pdo = Connection::open($config['db']);
            $problem = $this->existingInstallProblem($pdo);
            if ($problem !== null) {
                throw new \RuntimeException($problem);
            }
            $before = Backup::listTables($pdo);
            $migrations = (new Migrator($pdo))->migrate();
            $owned = array_values(array_diff(Backup::listTables($pdo), $before));
            if (!in_array('settings', $owned, true) && in_array('settings', $before, true)) {
                // Nastavak prekinute instalacije: tabele su već bile tu.
                $owned = array_values(array_unique(array_merge($owned, array_intersect($before, self::knownTables()))));
            }
            sort($owned);

            $repos = $this->repositories($pdo);
            $admin = $repos['users']->create($in['admin_username'], (new PasswordHasher())->hash($in['admin_password']), User::ROLE_ADMIN, null);
            $s = $repos['settings'];
            $s->set('accountant_name', trim($in['accountant_name']));
            $s->set('public_url', $publicUrl);
            $s->set('version', $version);
            $s->set('owned_tables', json_encode($owned));
            $s->set('installed_at', gmdate('Y-m-d H:i:s'));

            AppFiles::rename($tmp, $this->configFile());
            @chmod($this->configFile(), 0600);
            $s->set('install_lock', '1');
            $repos['audit']->log($admin->id, 'install', null, null, (string) ($in['ip'] ?? '0.0.0.0'),
                json_encode(['version' => $version, 'driver' => $db['driver']]) ?: null);
        } catch (\Throwable $e) {
            @unlink($tmp);
            if ($sqliteFile !== null && !$sqliteExisted && !is_file($this->configFile())) {
                unset($pdo, $repos, $s);
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($sqliteFile . $suffix);
                }
            }
            if ($e instanceof \PDOException) {
                throw new \RuntimeException(self::explainDbError($e), 0, $e);
            }
            throw $e;
        }
        @unlink($this->storage() . '/' . self::TOKEN_FILE);
        return ['version' => $version, 'public_url' => $publicUrl, 'migrations' => $migrations, 'user_id' => $admin->id];
    }

    /** Tabele iz početne šeme (za nastavak prekinute instalacije). @return list<string> */
    public static function knownTables(): array
    {
        return ['api_rate', 'audit_log', 'clients', 'login_attempts', 'receipts', 'schema_version', 'settings', 'users'];
    }

    public static function configContents(array $config): string
    {
        return "<?php\n"
            . "// Računator panel — konfiguracija instalacije (napravio instaler " . gmdate('Y-m-d H:i') . " UTC).\n"
            . "// Sadrži lozinku baze i tajni ključ instalacije: ne delite ovaj fajl. Prava pristupa: 0600.\n"
            . "return " . var_export($config, true) . ";\n";
    }

    /**
     * Provera da panel odgovara na javnoj adresi: GET <public_url>/health → {"ok":true}.
     * @return array{ok:bool,message:string}
     */
    public static function checkHealth(HttpClient $http, string $publicUrl): array
    {
        try {
            $res = $http->get(rtrim($publicUrl, '/') . '/health', ['Accept' => 'application/json', 'User-Agent' => 'racunator-panel-instaler']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Server nije mogao da pozove sam sebe (' . $e->getMessage() . '). Otvorite '
                . rtrim($publicUrl, '/') . '/health u pregledaču — treba da piše {"ok":true,…}.'];
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] === 200 && is_array($json) && ($json['ok'] ?? false) === true) {
            return ['ok' => true, 'message' => 'Panel odgovara na ' . rtrim($publicUrl, '/') . '/health (verzija ' . ($json['version'] ?? '?') . ').'];
        }
        return ['ok' => false, 'message' => '/health je vratio status ' . $res['status'] . '. Najčešći uzrok: mod_rewrite ili AllowOverride '
            . 'nisu uključeni, ili javna adresa ne odgovara folderu instalacije.'];
    }

    /** Briše samoraspakujući install fajl iz korena (ako je zapisan pri raspakivanju). Vraća ime obrisanog fajla ili null. */
    public function removeStub(): ?string
    {
        $info = $this->storage() . '/' . self::STUB_INFO;
        if (!is_file($info)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($info), true);
        @unlink($info);
        $name = is_array($data) ? (string) ($data['file'] ?? '') : '';
        if ($name === '' || basename($name) !== $name || !str_ends_with($name, '.php')) {
            return null;
        }
        $path = $this->root . '/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if (!str_contains($head, 'RACUNATOR_PANEL_INSTALLER')) {
            return null;
        }
        return @unlink($path) ? $name : null;
    }

    /** Provera tokena koji je install fajl dao čarobnjaku (sprečava da neko drugi završi instalaciju). */
    public function tokenMatches(?string $tokenHash): bool
    {
        $file = $this->storage() . '/' . self::TOKEN_FILE;
        if (!is_file($file)) {
            return true; // ručno otpremljeni fajlovi bez install fajla — nema tokena
        }
        return $tokenHash !== null && hash_equals(trim((string) file_get_contents($file)), $tokenHash);
    }

    public function hasToken(): bool
    {
        return is_file($this->storage() . '/' . self::TOKEN_FILE);
    }
}
