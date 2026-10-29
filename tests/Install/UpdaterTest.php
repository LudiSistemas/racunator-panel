<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Install\Updater;
use RacunatorPanel\Services\Maintenance;
use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Services\ReleaseInfo;


final class UpdaterTest extends InstallTestCase
{
    /** @return array{0:TestUpdater,1:array} */
    private function setUpUpdate(string $from = '1.0.0', string $to = '1.1.0', ?FakeHttpClient $http = null): array
    {
        $inst = $this->installation($from);
        $pkg = $inst['root'] . '/releases/' . $to;
        $this->makeApp($pkg, $to, 'novo');
        file_put_contents($pkg . '/.htaccess', '# .htaccess iz paketa');
        $this->writeManifest($pkg);
        $u = new TestUpdater($inst['root'], $inst['pdo'], $inst['settings'], $inst['audit'], $http, 'https://knjige.rs/panel');
        return [$u, $inst + ['pkg' => $pkg]];
    }

    private function auditActions(\PDO $pdo): array
    {
        return array_column($pdo->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(), 'action');
    }

    public function testUspesnoRucnoAzuriranje(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $u->health = ['ok', 'ok'];
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $root = $i['root'];

        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('1.1.0', $i['settings']->get('version'));
        $this->assertStringContainsString('1.1.0 novo', (string) file_get_contents($root . '/src/marker.txt'));
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($root . '/_old/src/marker.txt'));
        $this->assertStringContainsString('1.1.0', (string) file_get_contents($root . '/CHANGELOG.md'));
        // Ne diraju se: config.php, korisnikov .htaccess, storage/.
        $this->assertSame("<?php return ['tajna' => 'ostaje'];\n", file_get_contents($root . '/config.php'));
        $this->assertSame('# korisnikov .htaccess', file_get_contents($root . '/.htaccess'));
        $this->assertFileExists($root . '/.htaccess.nova-1.1.0');
        $this->assertFileExists($root . '/storage/panel.sqlite');
        $this->assertTrue($u->flagSeenDuringMigrations, 'maintenance.flag mora postojati tokom migracija');
        $this->assertFileDoesNotExist($root . '/storage/maintenance.flag');
        $this->assertDirectoryDoesNotExist($root . '/releases');
        $this->assertNotNull($res['backup']);
        $this->assertFileExists($root . '/storage/backups/' . $res['backup'] . '/fajlovi.zip');
        $this->assertFileExists($root . '/storage/backups/' . $res['backup'] . '/baza.sqlite');
        $log = (string) file_get_contents($root . '/storage/logs/update.log');
        foreach (['preduslovi', 'odrzavanje', 'rezervna_kopija', 'zamena', 'migracije', 'samoprovera', 'kraj'] as $step) {
            $this->assertStringContainsString($step, $log);
        }
        $this->assertSame(true, $u->lastResult()['ok']);
        $this->assertContains('update.run', $this->auditActions($i['pdo']));
    }

    public function testOstecenPaketSeOdbijaPreZamene(): void
    {
        [$u, $i] = $this->setUpUpdate();
        file_put_contents($i['pkg'] . '/src/marker.txt', 'podmetnuto');
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('SHA-256', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
        $this->assertContains('update.failed', $this->auditActions($i['pdo']));
    }

    public function testPadMigracijeVracaFajloveIBazu(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $u->migrations = static function (\PDO $pdo): array {
            $pdo->exec("CREATE TABLE nova_tabela (id INTEGER)");
            $pdo->exec("INSERT INTO settings (name, value) VALUES ('posle', 'x')");
            throw new \RuntimeException('Migracija 0002_test nije uspela: veštački');
        };
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['rolled_back']);
        $this->assertStringContainsString('veštački', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertNull($i['settings']->get('posle'));
        $tables = \RacunatorPanel\Services\Backup::listTables($i['pdo']);
        $this->assertNotContains('nova_tabela', $tables);
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
        $this->assertContains('update.failed', $this->auditActions($i['pdo']));
    }

    public function testPadSamoprovereVracaPrethodnuVerziju(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $u->health = ['ok', '/health je vratio status 500'];
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['rolled_back']);
        $this->assertStringContainsString('Samoprovera', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
    }

    public function testNedostupnaSamoproveraJeSamoUpozorenje(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertTrue($res['ok']);
        $statuses = array_column($res['steps'], 'status', 'step');
        $this->assertSame('warn', $statuses['samoprovera']);
    }

    public function testStarijiPaketSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate('2.0.0', '1.1.0');
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('stariji', (string) $res['error']);
        $this->assertDirectoryDoesNotExist($i['root'] . '/_old');
    }

    public function testNeupisivKorenSeJavljaPrePocetka(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root zaobilazi prava pristupa');
        }
        [$u, $i] = $this->setUpUpdate();
        chmod($i['root'] . '/src', 0555);
        try {
            $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        } finally {
            chmod($i['root'] . '/src', 0755);
        }
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Preduslovi', (string) $res['error']);
        $this->assertDirectoryDoesNotExist($i['root'] . '/storage/backups/' . ($res['backup'] ?? 'x'));
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
    }

    public function testPovratakIzRezervneKopije(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $res = $u->updateFromDirectory($i['pkg'], 1, '10.0.0.1');
        $this->assertTrue($res['ok']);
        $i['settings']->set('posle_azuriranja', 'da');
        $rev = $u->revert((string) $res['backup'], 1, '10.0.0.1');
        $this->assertTrue($rev['ok'], (string) $rev['error']);
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertNull($i['settings']->get('posle_azuriranja'));
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame("<?php return ['tajna' => 'ostaje'];\n", file_get_contents($i['root'] . '/config.php'));
        $this->assertContains('update.revert', $this->auditActions($i['pdo']));
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
    }

    public function testAzuriranjeSaGithubaSaLaznimKlijentom(): void
    {
        $http = new FakeHttpClient();
        [$u, $i] = $this->setUpUpdate('1.0.0', '1.1.0', $http);
        $zip = $this->tmp . '/racunator-panel-1.1.0.zip';
        $this->zipDir($i['pkg'], $zip);
        InstallTestCase::rrmdir($i['root'] . '/releases');
        $bytes = (string) file_get_contents($zip);
        $base = ReleaseChecker::DOWNLOAD_PREFIX . 'v1.1.0/';
        $http->routes[$base . 'racunator-panel-1.1.0.zip'] = ['status' => 200, 'body' => $bytes];
        $http->routes[$base . 'SHA256SUMS'] = ['status' => 200, 'body' => hash('sha256', $bytes) . "  racunator-panel-1.1.0.zip\n"];
        $http->routes[$base . 'racunator-panel-1.1.0.zip.sig'] = ['status' => 200, 'body' => base64_encode(str_repeat("\0", 64))];
        $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', "- novo\n", null, null, $base . 'racunator-panel-1.1.0.zip',
            strlen($bytes), $base . 'racunator-panel-1.1.0.zip.sig', $base . 'SHA256SUMS', null);

        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('1.1.0', $i['settings']->get('version'));
        $statuses = array_column($res['steps'], 'status', 'step');
        // Javni ključ je placeholder → potpis se ne može proveriti: upozorenje i zapis u dnevnik radnji.
        $this->assertSame('warn', $statuses['potpis']);
        $this->assertContains('update.warning', $this->auditActions($i['pdo']));
        $this->assertDirectoryDoesNotExist($i['root'] . '/storage/tmp/azuriranje-1.1.0');
    }

    public function testPogresanSha256SaGithubaSeOdbija(): void
    {
        $http = new FakeHttpClient();
        [$u, $i] = $this->setUpUpdate('1.0.0', '1.1.0', $http);
        $base = ReleaseChecker::DOWNLOAD_PREFIX . 'v1.1.0/';
        $http->routes[$base . 'racunator-panel-1.1.0.zip'] = ['status' => 200, 'body' => 'nije zip'];
        $http->routes[$base . 'SHA256SUMS'] = ['status' => 200, 'body' => str_repeat('a', 64) . "  racunator-panel-1.1.0.zip\n"];
        $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', '', null, null, $base . 'racunator-panel-1.1.0.zip', 8, null, $base . 'SHA256SUMS', null);
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('SHA-256', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
    }

    public function testTudjaAdresaPaketaSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate('1.0.0', '1.1.0', new FakeHttpClient());
        $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', '', null, null, 'https://zlo.example/p.zip', 8, null, 'https://zlo.example/SHA256SUMS', null);
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('zvaničnom repou', (string) $res['error']);
    }

    public function testIstekliMaintenanceFlagSeNePostuje(): void
    {
        Maintenance::enable($this->tmp, 'test', 60);
        $this->assertTrue(Maintenance::isActive($this->tmp));
        $this->assertFalse(Maintenance::isActive($this->tmp, time() + 120));
        Maintenance::disable($this->tmp);
        $this->assertFalse(Maintenance::isActive($this->tmp));
    }
}
