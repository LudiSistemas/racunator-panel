<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Db\Connection;
use RacunatorPanel\Db\Migrator;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;

require_once __DIR__ . '/../../install/bootstrap.php';
require_once __DIR__ . '/../../tools/lib/Package.php';

/** Zajedničko za testove instalacije: privremeni direktorijum i lažna instalacija sa SQLite bazom. */
abstract class InstallTestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rp-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmp);
    }

    public static function rrmdir(string $p): void
    {
        if (is_link($p) || is_file($p)) {
            @chmod($p, 0644);
            unlink($p);
            return;
        }
        if (!is_dir($p)) {
            return;
        }
        @chmod($p, 0755);
        foreach (scandir($p) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                self::rrmdir($p . '/' . $e);
            }
        }
        rmdir($p);
    }

    /** Fajlovi lažne verzije aplikacije u $dir (public, src, install, docs + koreni fajlovi). */
    protected function makeApp(string $dir, string $version, string $marker = ''): void
    {
        foreach (['public', 'src', 'install', 'docs'] as $d) {
            @mkdir($dir . '/' . $d, 0755, true);
            file_put_contents($dir . '/' . $d . '/marker.txt', $d . ' ' . $version . ' ' . $marker);
        }
        file_put_contents($dir . '/src/version.php', "<?php\nreturn '" . $version . "';\n");
        file_put_contents($dir . '/public/index.php', "<?php // " . $version . "\n");
        file_put_contents($dir . '/CHANGELOG.md', "# Izmene\n\n## " . $version . "\n- nešto\n");
        file_put_contents($dir . '/LICENSE', 'MIT');
        file_put_contents($dir . '/README.md', 'Računator panel ' . $version);
    }

    /** MANIFEST.sha256 za direktorijum (svi fajlovi). */
    protected function writeManifest(string $dir): void
    {
        $lines = "# test\n";
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($f->isFile() && $rel !== 'MANIFEST.sha256') {
                $files[] = $rel;
            }
        }
        sort($files);
        foreach ($files as $rel) {
            $lines .= hash_file('sha256', $dir . '/' . $rel) . '  ' . $rel . "\n";
        }
        file_put_contents($dir . '/MANIFEST.sha256', $lines);
    }

    /** ZIP direktorijuma. */
    protected function zipDir(string $dir, string $zipFile): void
    {
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($dir) + 1));
            }
        }
        $zip->close();
    }

    /**
     * Instalacija verzije $version u $this->tmp/root sa SQLite bazom i primenjenim migracijama.
     * @return array{root:string,pdo:\PDO,settings:PdoSettingsRepository,audit:PdoAuditLogRepository}
     */
    protected function installation(string $version = '1.0.0'): array
    {
        $root = $this->tmp . '/root';
        mkdir($root . '/storage', 0755, true);
        $this->makeApp($root, $version, 'staro');
        file_put_contents($root . '/.htaccess', '# korisnikov .htaccess');
        file_put_contents($root . '/config.php', "<?php return ['tajna' => 'ostaje'];\n");
        $pdo = Connection::open(['driver' => 'sqlite', 'dsn' => 'sqlite:' . $root . '/storage/panel.sqlite']);
        (new Migrator($pdo))->migrate();
        (new \RacunatorPanel\Repositories\PdoUserRepository($pdo))->create('admin', password_hash('x', PASSWORD_DEFAULT), 'admin', null);
        $settings = new PdoSettingsRepository($pdo);
        $settings->set('version', $version);
        $settings->set('install_lock', '1');
        $settings->set(\RacunatorPanel\Services\Terms::SETTING_VERSION, (string) \RacunatorPanel\Services\Terms::VERSION);
        $settings->set('owned_tables', json_encode(\RacunatorPanel\Services\Backup::listTables($pdo)));
        return ['root' => $root, 'pdo' => $pdo, 'settings' => $settings, 'audit' => new PdoAuditLogRepository($pdo)];
    }
}
