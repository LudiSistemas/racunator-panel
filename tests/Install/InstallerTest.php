<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Db\Connection;
use RacunatorPanel\Install\Installer;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Repositories\PdoUserRepository;

final class InstallerTest extends InstallTestCase
{
    private function root(): string
    {
        $root = $this->tmp . '/root';
        mkdir($root . '/src', 0755, true);
        file_put_contents($root . '/src/version.php', "<?php\nreturn '1.2.3';\n");
        return $root;
    }

    private function input(Installer $i, array $over = []): array
    {
        return $over + [
            'db' => $i->dbConfig(['driver' => 'sqlite']),
            'public_url' => 'https://knjige.rs/panel/',
            'admin_username' => 'marko',
            'admin_password' => 'Plavi-kofer-7-oblaka',
            'accountant_name' => 'Knjigovodstvo Marković',
            'ip' => '10.0.0.1',
        ];
    }

    public function testInstalacijaSaSqliteom(): void
    {
        $root = $this->root();
        $i = new Installer($root);
        $this->assertFalse($i->isInstalled());
        $res = $i->install($this->input($i));

        $this->assertSame('1.2.3', $res['version']);
        $this->assertSame('https://knjige.rs/panel', $res['public_url']);
        $this->assertContains('0001_init', $res['migrations']);
        $this->assertTrue($i->isInstalled());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($root . '/config.php')), -4));
        $this->assertFileDoesNotExist($root . '/config.php.instalacija');

        $cfg = require $root . '/config.php';
        $this->assertSame('sqlite', $cfg['db']['driver']);
        $this->assertSame('sqlite:' . $root . '/storage/panel.sqlite', $cfg['db']['dsn']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cfg['app_key']);
        $this->assertSame($root . '/storage', $cfg['storage']);
        $this->assertSame('https://knjige.rs/panel', $cfg['public_url']);

        foreach (['backups', 'tmp', 'logs'] as $d) {
            $this->assertDirectoryExists($root . '/storage/' . $d);
        }
        $this->assertStringContainsString('denied', (string) file_get_contents($root . '/storage/.htaccess'));

        $pdo = Connection::open($cfg['db']);
        $s = new PdoSettingsRepository($pdo);
        $this->assertSame('1', $s->get('install_lock'));
        $this->assertSame('1.2.3', $s->get('version'));
        $this->assertSame('Knjigovodstvo Marković', $s->get('accountant_name'));
        $this->assertSame('https://knjige.rs/panel', $s->get('public_url'));
        $owned = json_decode((string) $s->get('owned_tables'), true);
        $this->assertContains('receipts', $owned);
        $this->assertContains('settings', $owned);

        $admin = (new PdoUserRepository($pdo))->findByUsername('marko');
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(password_verify('Plavi-kofer-7-oblaka', $admin->passwordHash));
        $actions = array_column($pdo->query('SELECT action FROM audit_log')->fetchAll(), 'action');
        $this->assertSame(['install'], $actions);
    }

    public function testDrugaInstalacijaSeOdbija(): void
    {
        $root = $this->root();
        $i = new Installer($root);
        $i->install($this->input($i));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('već instaliran');
        $i->install($this->input($i, ['admin_username' => 'drugi']));
    }

    public function testBazaSaZavrsenomInstalacijomSeOdbija(): void
    {
        $root = $this->root();
        $i = new Installer($root);
        $i->install($this->input($i));
        unlink($root . '/config.php'); // neko je obrisao config, baza je ostala
        $this->assertStringContainsString('već sadrži', (string) $i->testDatabase($i->dbConfig(['driver' => 'sqlite'])));
        try {
            $i->install($this->input($i));
            $this->fail('Instalacija preko postojeće baze mora biti odbijena.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('već sadrži', $e->getMessage());
        }
        $this->assertFileDoesNotExist($root . '/config.php');
        $this->assertFileDoesNotExist($root . '/config.php.instalacija');
    }

    public function testNeuspelaInstalacijaNeOstavljaConfigNiSqlite(): void
    {
        $root = $this->root();
        $i = new Installer($root, static function (\PDO $pdo): array {
            throw new \RuntimeException('veštačka greška');
        });
        try {
            $i->install($this->input($i));
            $this->fail('Očekivana greška.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('veštačka', $e->getMessage());
        }
        $this->assertFalse($i->isInstalled());
        $this->assertFileDoesNotExist($root . '/config.php.instalacija');
        $this->assertFileDoesNotExist($root . '/storage/panel.sqlite');
    }

    public function testNeispravanUlazSeOdbija(): void
    {
        $i = new Installer($this->root());
        $this->expectException(\InvalidArgumentException::class);
        $i->install($this->input($i, ['admin_password' => 'kratka']));
    }

    public function testMysqlKonfiguracija(): void
    {
        $i = new Installer($this->root());
        $db = $i->dbConfig(['driver' => 'mysql', 'host' => 'localhost', 'port' => '3307', 'name' => 'nalog_panel', 'user' => 'u', 'pass' => 'p"\'']);
        $this->assertSame('mysql:host=localhost;port=3307;dbname=nalog_panel;charset=utf8mb4', $db['dsn']);
        $this->assertSame('p"\'', $db['pass']);
        $this->expectException(\InvalidArgumentException::class);
        $i->dbConfig(['driver' => 'mysql', 'host' => 'localhost', 'name' => 'x;host=zlo']);
    }

    public function testConfigSaPosebnimZnakovimaJeIspravanPhp(): void
    {
        $cfg = ['db' => ['driver' => 'mysql', 'dsn' => 'mysql:host=a', 'user' => "o'neil", 'pass' => "\$x'\"\\n;?>"], 'public_url' => 'https://a.rs', 'app_key' => str_repeat('a', 64), 'storage' => '/s'];
        file_put_contents($this->tmp . '/c.php', Installer::configContents($cfg));
        $this->assertSame($cfg, require $this->tmp . '/c.php');
    }

    public function testJacinaLozinke(): void
    {
        $this->assertFalse(Installer::passwordStrength('kratka')['ok']);
        $this->assertSame(0, Installer::passwordStrength('aaaaaaaaaaaaaa')['score']);
        $this->assertFalse(Installer::passwordStrength('marko-ima-dugu-lozinku', 'marko')['ok']);
        $this->assertTrue(Installer::passwordStrength('dugackalozinka')['ok']);
        $this->assertSame(1, Installer::passwordStrength('dugackalozinka')['score']);
        $this->assertGreaterThanOrEqual(3, Installer::passwordStrength('Plavi-Kofer-7-Oblaka-Nad-Gradom')['score']);
        $errors = Installer::validateAdmin('ma', 'Plavi-kofer-7-oblaka', 'drugacija', '');
        $this->assertCount(3, $errors);
    }

    public function testJavnaAdresa(): void
    {
        $this->assertSame('https://a.rs/panel', Installer::normalizePublicUrl(' https://a.rs/panel/ '));
        foreach (['ftp://a.rs', 'a.rs/panel', 'https://a.rs/?x=1', 'https://u:p@a.rs', 'https://a.rs/"><script>'] as $bad) {
            try {
                Installer::normalizePublicUrl($bad);
                $this->fail('Prihvaćena loša adresa: ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('https://knjige.rs/panel', Installer::suggestPublicUrl(['HTTPS' => 'on', 'HTTP_HOST' => 'knjige.rs', 'SCRIPT_NAME' => '/panel/install/index.php']));
        $this->assertSame('http://knjige.rs', Installer::suggestPublicUrl(['HTTP_HOST' => 'knjige.rs', 'SCRIPT_NAME' => '/install/index.php']));
        $this->assertSame('http://localhost/p', Installer::suggestPublicUrl(['HTTP_HOST' => 'evil"host', 'SCRIPT_NAME' => '/p/install/index.php']));
    }

    public function testBrisanjeInstallFajlaITokena(): void
    {
        $root = $this->root();
        $i = new Installer($root);
        $i->ensureStorage();
        file_put_contents($root . '/racunator-panel-install.php', "<?php\n// RACUNATOR_PANEL_INSTALLER\n");
        file_put_contents($root . '/tudji.php', "<?php // nije naš\n");
        file_put_contents($root . '/storage/tmp/installer-stub.json', json_encode(['file' => 'tudji.php']));
        $this->assertNull($i->removeStub());
        $this->assertFileExists($root . '/tudji.php');
        file_put_contents($root . '/storage/tmp/installer-stub.json', json_encode(['file' => '../van.php']));
        $this->assertNull($i->removeStub());
        file_put_contents($root . '/storage/tmp/installer-stub.json', json_encode(['file' => 'racunator-panel-install.php']));
        $this->assertSame('racunator-panel-install.php', $i->removeStub());
        $this->assertFileDoesNotExist($root . '/racunator-panel-install.php');

        $this->assertTrue($i->tokenMatches(null)); // nema tokena = ručno otpremljeni fajlovi
        file_put_contents($root . '/storage/tmp/install-token', hash('sha256', 'tajna'));
        $this->assertFalse($i->tokenMatches(null));
        $this->assertFalse($i->tokenMatches(hash('sha256', 'pogresno')));
        $this->assertTrue($i->tokenMatches(hash('sha256', 'tajna')));
    }

    public function testObjasnjenjeGresakaBaze(): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [1045] Access denied for user');
        $this->assertStringContainsString('Pogrešno korisničko ime ili lozinka', Installer::explainDbError($e));
        $e = new \PDOException('SQLSTATE[HY000] [2002] No such file or directory');
        $this->assertStringContainsString('localhost', Installer::explainDbError($e));
    }
}
