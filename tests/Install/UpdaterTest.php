<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Install\Updater;
use RacunatorPanel\Services\Maintenance;
use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Services\ReleaseInfo;


final class UpdaterTest extends InstallTestCase
{
    /** @var array{0:string,1:string}|null [javni ključ base64, tajni ključ] */
    private ?array $keys = null;

    protected function tearDown(): void
    {
        TestUpdater::$noSodium = false;
        parent::tearDown();
    }

    /** @return array{0:TestUpdater,1:array} */
    private function setUpUpdate(string $from = '1.0.0', string $to = '1.1.0', ?FakeHttpClient $http = null, ?string $publicKey = null): array
    {
        $inst = $this->installation($from);
        $pkg = $this->tmp . '/paket-' . $to;
        $this->makeApp($pkg, $to, 'novo');
        file_put_contents($pkg . '/htaccess.dist', '# .htaccess iz paketa');
        $this->writeManifest($pkg);
        $publicKey ??= $this->keys()[0];
        $u = new TestUpdater($inst['root'], $inst['pdo'], $inst['settings'], $inst['audit'], $http, 'https://knjige.rs/panel', null, $publicKey);
        return [$u, $inst + ['pkg' => $pkg]];
    }

    /** @return array{0:string,1:string} */
    private function keys(): array
    {
        return $this->keys ??= $this->keypair();
    }

    /**
     * ZIP paketa i njegov potpis (kao što ih install fajl ostavlja u storage/tmp/manual-update/).
     * @return array{0:string,1:?string} [zip, sig]
     */
    private function pack(array $i, bool $sign = true, ?string $secret = null): array
    {
        $zip = $this->tmp . '/racunator-panel-' . basename($i['pkg']) . '.zip';
        @unlink($zip);
        $this->zipDir($i['pkg'], $zip);
        if (!$sign) {
            return [$zip, null];
        }
        file_put_contents($zip . '.sig', base64_encode(sodium_crypto_sign_detached((string) file_get_contents($zip), $secret ?? $this->keys()[1])) . "\n");
        return [$zip, $zip . '.sig'];
    }

    private function manual(TestUpdater $u, array $i, bool $allowUnverified = false): array
    {
        [$zip, $sig] = $this->pack($i);
        return $u->updateFromPackage($zip, $sig, 1, '10.0.0.1', $allowUnverified);
    }

    private function auditActions(\PDO $pdo): array
    {
        return array_column($pdo->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(), 'action');
    }

    public function testUspesnoRucnoAzuriranje(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $u->health = ['ok', 'ok'];
        $res = $this->manual($u, $i);
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
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($root . '/_old/.htaccess'));
        $this->assertStringContainsString('<If "true">', (string) file_get_contents($root . '/_old/.htaccess'));
        $this->assertSame('ok', array_column($res['steps'], 'status', 'step')['potpis']);
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
        file_put_contents($i['pkg'] . '/src/marker.txt', 'podmetnuto'); // potpisan ZIP, ali manifest se ne slaže
        $res = $this->manual($u, $i);
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
        $res = $this->manual($u, $i);
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
        $res = $this->manual($u, $i);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['rolled_back']);
        $this->assertStringContainsString('Samoprovera', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
    }

    public function testNedostupnaSamoproveraJeSamoUpozorenje(): void
    {
        [$u, $i] = $this->setUpUpdate();
        $res = $this->manual($u, $i);
        $this->assertTrue($res['ok']);
        $statuses = array_column($res['steps'], 'status', 'step');
        $this->assertSame('warn', $statuses['samoprovera']);
    }

    public function testStarijiPaketSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate('2.0.0', '1.1.0');
        $res = $this->manual($u, $i);
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
            $res = $this->manual($u, $i);
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
        $res = $this->manual($u, $i);
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

    /**
     * Lažno izdanje 1.1.0 na „GitHub-u": ZIP, SHA256SUMS i (opciono) .sig sa datim sadržajem.
     * @return array{0:TestUpdater,1:array,2:ReleaseInfo,3:string,4:FakeHttpClient} updater, instalacija, izdanje, bajtovi ZIP-a, HTTP
     */
    private function githubRelease(?string $publicKey, ?\Closure $sig): array
    {
        $http = new FakeHttpClient();
        [$u, $i] = $this->setUpUpdate('1.0.0', '1.1.0', $http, $publicKey);
        $zip = $this->tmp . '/racunator-panel-1.1.0.zip';
        $this->zipDir($i['pkg'], $zip);
        $bytes = (string) file_get_contents($zip);
        $base = ReleaseChecker::DOWNLOAD_PREFIX . 'v1.1.0/';
        $http->routes[$base . 'racunator-panel-1.1.0.zip'] = ['status' => 200, 'body' => $bytes];
        $http->routes[$base . 'SHA256SUMS'] = ['status' => 200, 'body' => hash('sha256', $bytes) . "  racunator-panel-1.1.0.zip\n"];
        if ($sig !== null) {
            $http->routes[$base . 'racunator-panel-1.1.0.zip.sig'] = ['status' => 200, 'body' => $sig($bytes)];
        }
        $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', "- novo\n", null, null, $base . 'racunator-panel-1.1.0.zip',
            strlen($bytes), $sig !== null ? $base . 'racunator-panel-1.1.0.zip.sig' : null, $base . 'SHA256SUMS', null);
        return [$u, $i, $release, $bytes, $http];
    }

    /** @return array{0:string,1:string} [javni ključ base64, tajni ključ] */
    private function keypair(): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('nema sodium');
        }
        $kp = sodium_crypto_sign_keypair();
        return [base64_encode(sodium_crypto_sign_publickey($kp)), sodium_crypto_sign_secretkey($kp)];
    }

    public function testAzuriranjeSaGithubaSaIspravnimPotpisom(): void
    {
        [$pk, $sk] = $this->keypair();
        [$u, $i, $release] = $this->githubRelease($pk, static fn (string $zip): string => base64_encode(sodium_crypto_sign_detached($zip, $sk)) . "\n");
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('1.1.0', $i['settings']->get('version'));
        $statuses = array_column($res['steps'], 'status', 'step');
        $this->assertSame('ok', $statuses['potpis']);
        $this->assertNotContains('update.warning', $this->auditActions($i['pdo']));
        $this->assertDirectoryDoesNotExist($i['root'] . '/storage/tmp/azuriranje-1.1.0');
    }

    public function testPaketSaPogresnimPotpisomSeOdbija(): void
    {
        [$pk] = $this->keypair();
        [, $tudjiSk] = $this->keypair(); // potpisano drugim ključem
        [$u, $i, $release] = $this->githubRelease($pk, static fn (string $zip): string => base64_encode(sodium_crypto_sign_detached($zip, $tudjiSk)));
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('potpis', (string) $res['error']);
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertDirectoryDoesNotExist($i['root'] . '/_old');
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
    }

    public function testPaketBezPotpisaSeOdbijaKadJeKljucPostavljen(): void
    {
        [$pk] = $this->keypair();
        [$u, $i, $release] = $this->githubRelease($pk, null);
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('nema digitalni potpis', (string) $res['error']);
        $this->assertSame('1.0.0', $i['settings']->get('version'));
    }

    public function testBezJavnogKljucaMreznoAzuriranjeSeOdbija(): void
    {
        [$u, $i, $release] = $this->githubRelease('ZAMENITI-JAVNIM-KLJUCEM', static fn (string $zip): string => base64_encode(str_repeat("\0", 64)));
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Preduslovi', (string) $res['error']);
        $this->assertStringContainsString('javni ključ', implode(' ', array_column($res['steps'], 'message')));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
    }

    public function testBezSodiumaMreznoAzuriranjeSeOdbijaBezPreuzimanja(): void
    {
        [, $sk] = $this->keys();
        [$u, $i, $release, , $http] = $this->githubRelease(null, static fn (string $zip): string => base64_encode(sodium_crypto_sign_detached($zip, $sk)));
        TestUpdater::$noSodium = true;
        $this->assertFalse($u->canVerifySignatures());
        $this->assertStringContainsString('sodium', implode(' ', $u->preflight(null, true)));
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('sodium', implode(' ', array_column($res['steps'], 'message')));
        $this->assertSame([], array_filter(array_column($http->calls, 'url'), static fn (string $url): bool => str_contains($url, '.zip')), 'ništa se ne preuzima');
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertDirectoryDoesNotExist($i['root'] . '/storage/backups/' . ($res['backup'] ?? 'x'));
    }

    public function testRucnoAzuriranjeBezPotpisaSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate();
        [$zip] = $this->pack($i, false);
        $res = $u->updateFromPackage($zip, null, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('nema digitalni potpis', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertDirectoryDoesNotExist($i['root'] . '/_old');
        $this->assertNull($res['backup'], 'bez potpisa se ništa ne radi — ni rezervna kopija');
        $this->assertFileDoesNotExist($i['root'] . '/storage/maintenance.flag');
    }

    public function testRucnoAzuriranjeSaTudjimPotpisomSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate();
        [, $tudji] = $this->keypair();
        [$zip, $sig] = $this->pack($i, true, $tudji);
        $res = $u->updateFromPackage($zip, $sig, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('potpis paketa nije ispravan', (string) $res['error']);
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        $this->assertDirectoryDoesNotExist($i['root'] . '/releases/1.1.0', 'neprovereni paket se ne raspakuje');
    }

    public function testVisakFajlaKojegNemaUManifestuSeOdbija(): void
    {
        [$u, $i] = $this->setUpUpdate();
        file_put_contents($i['pkg'] . '/public/zlo.php', '<?php system($_GET["c"]);'); // potpisan ZIP, fajl van manifesta
        $res = $this->manual($u, $i);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('nije u manifestu: public/zlo.php', (string) $res['error']);
        $this->assertFileDoesNotExist($i['root'] . '/public/zlo.php');
        $this->assertDirectoryDoesNotExist($i['root'] . '/releases');
    }

    public function testPaketSaAktivnimHtaccessomIConfigomSeNeutralise(): void
    {
        [$u, $i] = $this->setUpUpdate();
        // Stari oblik paketa: koreni .htaccess (RewriteEngine) u releases/<v>/ bi poništio zabranu iz releases/.htaccess.
        file_put_contents($i['pkg'] . '/.htaccess', "RewriteEngine On\nRewriteRule ^install(/.*)?$ - [L]\n");
        $this->writeManifest($i['pkg']);
        $res = $this->manual($u, $i);
        $this->assertFalse($res['ok'], 'paket sa aktivnim .htaccess-om u korenu se ne prihvata');
        $this->assertStringContainsString('.htaccess', (string) $res['error']);
        $this->assertDirectoryDoesNotExist($i['root'] . '/releases/1.1.0');
    }

    public function testBezSodiumaRucnoAzuriranjeSamoUzPotvrduSha256(): void
    {
        [$u, $i] = $this->setUpUpdate();
        TestUpdater::$noSodium = true;
        $res = $this->manual($u, $i);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('ne može da se proveri', (string) $res['error']);
        $this->assertSame('1.0.0', $i['settings']->get('version'));

        $res = $this->manual($u, $i, true);
        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('warn', array_column($res['steps'], 'status', 'step')['potpis']);
        $this->assertContains('update.warning', $this->auditActions($i['pdo']));
    }

    public function testPogresanSha256SaGithubaSeOdbija(): void
    {
        $http = new FakeHttpClient();
        [$u, $i] = $this->setUpUpdate('1.0.0', '1.1.0', $http);
        $base = ReleaseChecker::DOWNLOAD_PREFIX . 'v1.1.0/';
        $http->routes[$base . 'racunator-panel-1.1.0.zip'] = ['status' => 200, 'body' => 'nije zip'];
        $http->routes[$base . 'SHA256SUMS'] = ['status' => 200, 'body' => str_repeat('a', 64) . "  racunator-panel-1.1.0.zip\n"];
        $http->routes[$base . 'racunator-panel-1.1.0.zip.sig'] = ['status' => 200, 'body' => base64_encode(str_repeat("\0", 64))];
        $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', '', null, null, $base . 'racunator-panel-1.1.0.zip', 8,
            $base . 'racunator-panel-1.1.0.zip.sig', $base . 'SHA256SUMS', null);
        $res = $u->updateFromRelease($release, 1, '10.0.0.1');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('SHA-256', (string) $res['error']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
    }

    public function testTudjaAdresaPaketaSeOdbija(): void
    {
        $http = new FakeHttpClient();
        [$u] = $this->setUpUpdate('1.0.0', '1.1.0', $http);
        $ok = ReleaseChecker::DOWNLOAD_PREFIX . 'v1.1.0/';
        foreach ([
            ['https://zlo.example/p.zip', 'https://zlo.example/SHA256SUMS'],
            [$ok . '../../../../zlo/zlo/releases/download/v1/p.zip', $ok . 'SHA256SUMS'],
            [$ok . '%2e%2e/p.zip', $ok . 'SHA256SUMS'],
            ['http://github.com/LudiSistemas/racunator-panel/releases/download/v1.1.0/p.zip', $ok . 'SHA256SUMS'],
        ] as [$zipUrl, $sumsUrl]) {
            $release = new ReleaseInfo('v1.1.0', '1.1.0', 'v1.1.0', '', null, null, $zipUrl, 8, $ok . 'p.zip.sig', $sumsUrl, null);
            $res = $u->updateFromRelease($release, 1, '10.0.0.1');
            $this->assertFalse($res['ok']);
            $this->assertStringContainsString('zvaničnom repou', (string) $res['error'], $zipUrl);
        }
        $this->assertSame([], $http->calls, 'ništa se ne preuzima sa tuđe adrese');
    }

    public function testSamoproveraSaljeTokenZaVerzijuNaHealth(): void
    {
        $this->assertSame(\RacunatorPanel\App::healthToken('tajni-kljuc'),
            Updater::healthHeaders('tajni-kljuc', 'x')[\RacunatorPanel\App::HEALTH_TOKEN_HEADER]);
        $this->assertArrayNotHasKey(\RacunatorPanel\App::HEALTH_TOKEN_HEADER, Updater::healthHeaders('', 'x'));

        // Pravi Updater (ne TestUpdater): /health vraća verziju samo uz ispravan token, kao App posle ažuriranja.
        $http = new FakeHttpClient();
        $inst = $this->installation('1.0.0');
        $version = '1.0.0';
        $http->routes['https://knjige.rs/panel/health'] = static function () use ($http, &$version): array {
            $headers = end($http->calls)['headers'];
            $ok = ($headers[\RacunatorPanel\App::HEALTH_TOKEN_HEADER] ?? '') === \RacunatorPanel\App::healthToken('tajni-kljuc');
            return ['status' => 200, 'body' => json_encode($ok ? ['ok' => true, 'version' => $version] : ['ok' => true])];
        };
        $pkg = $this->tmp . '/paket-1.1.0';
        $this->makeApp($pkg, '1.1.0', 'novo');
        $this->writeManifest($pkg);
        [$pk] = $this->keys();
        $u = new class ($inst['root'], $inst['pdo'], $inst['settings'], $inst['audit'], $http, 'https://knjige.rs/panel', null, $pk, 'tajni-kljuc', $version) extends Updater {
            public function __construct(string $root, \PDO $pdo, $settings, $audit, $http, string $url, ?string $storage, string $pk, string $appKey, private string &$ver)
            {
                parent::__construct($root, $pdo, $settings, $audit, $http, $url, $storage, $pk, $appKey);
            }

            protected function runMigrations(): array
            {
                $this->ver = '1.1.0'; // od ovog trenutka /health prijavljuje novu verziju
                return [];
            }
        };
        [$zip, $sig] = $this->pack($inst + ['pkg' => $pkg]);
        $res = $u->updateFromPackage($zip, $sig, 1, '10.0.0.1');
        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('ok', array_column($res['steps'], 'status', 'step')['samoprovera']);
        $this->assertStringContainsString('verzijom 1.1.0', array_column($res['steps'], 'message', 'step')['samoprovera']);
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
