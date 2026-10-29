<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Services\AppFiles;
use RacunatorPanel\Tools\Package;

/** Build stvarnog repoa: ZIP + samoraspakujući install fajl, raspakivanje u privremeni folder (sa i bez ext-zip). */
final class BuildInstallerTest extends InstallTestCase
{
    private static ?string $buildDir = null;
    /** Potpis ZIP-a ugrađen u install fajl (test ključ). */
    private static string $signature = '';

    private function build(): string
    {
        if (self::$buildDir === null || !is_file(self::$buildDir . '/' . Package::INSTALLER_NAME)) {
            self::$buildDir = sys_get_temp_dir() . '/rp-build-' . bin2hex(random_bytes(4));
            $root = dirname(__DIR__, 2);
            $zip = Package::buildZip($root, self::$buildDir . '/racunator-panel-' . Package::version($root) . '.zip');
            $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
            self::$signature = Package::signFile($zip['file'], $secret);
            Package::buildInstaller($root, $zip['file'], self::$buildDir . '/' . Package::INSTALLER_NAME, self::$signature);
        }
        return self::$buildDir;
    }

    /** Greške manifesta bez očekivanih razlika korena nove instalacije (.htaccess umesto htaccess.dist, storage/). */
    private static function installErrors(string $dir): array
    {
        return array_values(array_filter(AppFiles::verifyManifest($dir), static fn (string $e): bool =>
            $e !== 'Nedostaje fajl: htaccess.dist' && preg_match('#^Fajl nije u manifestu: (\.htaccess$|storage/|error_log$|racunator-panel-install\.php$)#', $e) !== 1));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$buildDir !== null) {
            InstallTestCase::rrmdir(self::$buildDir);
        }
    }

    /** @return array{0:int,1:string} izlazni kod i izlaz */
    private function runStub(string $dir, array $env = []): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/' . Package::INSTALLER_NAME) . ' 2>&1';
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
        exec($prefix . $cmd, $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    public function testPaketSadrziSveIManifest(): void
    {
        $root = dirname(__DIR__, 2);
        $build = $this->build();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($build . '/racunator-panel-' . Package::version($root) . '.zip'));
        foreach (['MANIFEST.sha256', 'src/version.php', 'public/index.php', 'install/index.php', 'install/Updater.php', 'htaccess.dist',
            'storage/.htaccess', 'LICENSE', 'README.md', 'CHANGELOG.md', 'src/Services/ReleaseChecker.php'] as $f) {
            $this->assertNotFalse($zip->locateName($f), $f);
        }
        $this->assertFalse($zip->locateName('.htaccess'), 'koreni .htaccess je u paketu samo kao htaccess.dist');
        $this->assertSame(file_get_contents($root . '/.htaccess'), $zip->getFromName('htaccess.dist'));
        $this->assertDoesNotMatchRegularExpression('/^\s*(Require all granted|Allow from all)/m', (string) $zip->getFromName('install/.htaccess'),
            'install/.htaccess ne sme da dozvoljava pristup (poništio bi zabranu iz releases/ i _old/)');
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $name = (string) $zip->getNameIndex($k);
            $this->assertDoesNotMatchRegularExpression('#^(tests|tools|vendor|plans|build|storage/(?!\.htaccess$))|\.DS_Store$|^config\.php$#', $name);
        }
        $zip->close();
        $head = (string) file_get_contents($build . '/' . Package::INSTALLER_NAME, false, null, 0, 4096);
        $this->assertStringContainsString('RACUNATOR_PANEL_INSTALLER', $head);
        $this->assertStringNotContainsString('@@VERSION@@', $head);
        $this->assertStringContainsString("define('RPI_SIGNATURE', '" . self::$signature . "')", $head);
    }

    /** @dataProvider nacini */
    public function testStubSeRaspakujeISha256SeSlaze(bool $miniZip): void
    {
        $dir = $this->tmp . '/sajt';
        mkdir($dir);
        copy($this->build() . '/' . Package::INSTALLER_NAME, $dir . '/' . Package::INSTALLER_NAME);
        file_put_contents($dir . '/error_log', ''); // uobičajen fajl hostinga je dozvoljen
        [$rc, $out] = $this->runStub($dir, $miniZip ? ['RPI_FORCE_MINIZIP' => '1'] : []);
        $this->assertSame(0, $rc, $out);
        $this->assertStringContainsString('raspakovan', $out);
        $this->assertSame([], self::installErrors($dir));
        $this->assertStringContainsString('Računator panel', (string) file_get_contents($dir . '/.htaccess'), 'nova instalacija dobija koreni .htaccess');
        $this->assertFileDoesNotExist($dir . '/htaccess.dist');
        $this->assertSame(Package::version(dirname(__DIR__, 2)), AppFiles::versionOf($dir));
        $this->assertFileExists($dir . '/install/index.php');
        $this->assertFileExists($dir . '/storage/.htaccess');
        $this->assertDirectoryExists($dir . '/storage/backups');
        $info = json_decode((string) file_get_contents($dir . '/storage/tmp/installer-stub.json'), true);
        $this->assertSame(Package::INSTALLER_NAME, $info['file']);
        // Uporedi sa izvorom: isti sadržaj kao u repou.
        $this->assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/src/Services/Backup.php'), hash_file('sha256', $dir . '/src/Services/Backup.php'));
    }

    public static function nacini(): array
    {
        return ['ext-zip' => [false], 'ugrađeni čitač' => [true]];
    }

    public function testStubOdbijaFolderKojiNijePrazan(): void
    {
        $dir = $this->tmp . '/pun';
        mkdir($dir);
        copy($this->build() . '/' . Package::INSTALLER_NAME, $dir . '/' . Package::INSTALLER_NAME);
        file_put_contents($dir . '/wp-config.php', '<?php');
        [$rc, $out] = $this->runStub($dir);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('Folder nije prazan', $out);
        $this->assertFileDoesNotExist($dir . '/src/version.php');
    }

    public function testOstecenStubSeOdbija(): void
    {
        $dir = $this->tmp . '/osteceno';
        mkdir($dir);
        $data = (string) file_get_contents($this->build() . '/' . Package::INSTALLER_NAME);
        $pos = strrpos($data, "\n", -200);
        $data[$pos + 10] = $data[$pos + 10] === 'A' ? 'B' : 'A';
        file_put_contents($dir . '/' . Package::INSTALLER_NAME, $data);
        [$rc, $out] = $this->runStub($dir);
        $this->assertSame(1, $rc);
        $this->assertStringContainsString('oštećen', $out);
        $this->assertFileDoesNotExist($dir . '/src/version.php');
    }

    public function testStubNadPostojecomInstalacijomRaspakujeURelease(): void
    {
        $dir = $this->tmp . '/postojeca';
        mkdir($dir . '/storage/tmp', 0755, true);
        file_put_contents($dir . '/config.php', "<?php return [];\n");
        copy($this->build() . '/' . Package::INSTALLER_NAME, $dir . '/' . Package::INSTALLER_NAME);
        $installerSha = hash_file('sha256', $dir . '/' . Package::INSTALLER_NAME);
        [$rc, $out] = $this->runStub($dir);
        $this->assertSame(0, $rc, $out);
        $v = Package::version(dirname(__DIR__, 2));
        $rel = $dir . '/releases/' . $v;
        $this->assertSame([], AppFiles::verifyManifest($rel));
        $this->assertFileDoesNotExist($rel . '/.htaccess', 'u releases/<v>/ nema aktivnog korenog .htaccess-a');
        $this->assertFileExists($rel . '/htaccess.dist');
        $this->assertSame(AppFiles::DENY_HTACCESS, file_get_contents($dir . '/releases/.htaccess'));
        $this->assertFileDoesNotExist($dir . '/src', 'postojeća instalacija se ne prepisuje');
        $this->assertFileDoesNotExist($dir . '/' . Package::INSTALLER_NAME, 'install fajl briše sebe');
        $pending = json_decode((string) file_get_contents($dir . '/storage/tmp/manual-update.json'), true);
        $this->assertSame($v, $pending['version']);
        $this->assertSame($installerSha, $pending['installer_sha256']);
        $zip = $dir . '/storage/tmp/manual-update/racunator-panel-' . $v . '.zip';
        $this->assertSame(hash_file('sha256', $this->build() . '/racunator-panel-' . $v . '.zip'), hash_file('sha256', $zip));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($zip)), -4));
        $this->assertSame(self::$signature, trim((string) file_get_contents($zip . '.sig')), 'potpis iz install fajla');
    }

    public function testStubCitaFolderZaPodatkeIzConfiga(): void
    {
        $dir = $this->tmp . '/postojeca';
        $data = $this->tmp . '/van-weba';
        mkdir($dir, 0755, true);
        mkdir($data . '/tmp', 0755, true);
        file_put_contents($dir . '/config.php', "<?php return ['storage' => " . var_export($data, true) . "];\n");
        copy($this->build() . '/' . Package::INSTALLER_NAME, $dir . '/' . Package::INSTALLER_NAME);
        [$rc, $out] = $this->runStub($dir);
        $this->assertSame(0, $rc, $out);
        $this->assertFileExists($data . '/tmp/manual-update.json');
        $this->assertDirectoryDoesNotExist($dir . '/storage', 'storage/ u web root-u se ne pravi');
    }

    /** Install fajl preko „veba" (ugrađeni PHP server): ključ za čarobnjak se izdaje samo jednom. */
    public function testDrugoOtvaranjeNeIzdajeNovKljuc(): void
    {
        $dir = $this->tmp . '/web';
        mkdir($dir);
        copy($this->build() . '/' . Package::INSTALLER_NAME, $dir . '/' . Package::INSTALLER_NAME);
        $port = 20000 + random_int(0, 20000);
        $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $get = static function (string $url): array {
                for ($k = 0; $k < 50; $k++) {
                    $ctx = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60]]);
                    $body = @file_get_contents($url, false, $ctx);
                    if ($body !== false) {
                        return [(string) ($http_response_header[0] ?? ''), implode("\n", $http_response_header), $body];
                    }
                    usleep(100000);
                }
                return ['', '', ''];
            };
            [$status, $headers] = $get('http://127.0.0.1:' . $port . '/' . Package::INSTALLER_NAME);
            $this->assertStringContainsString('303', $status);
            $this->assertMatchesRegularExpression('#Location: install/\?t=[0-9a-f]{32}#', $headers);
            $token = (string) file_get_contents($dir . '/storage/tmp/install-token');
            [$status2, $headers2, $body2] = $get('http://127.0.0.1:' . $port . '/' . Package::INSTALLER_NAME);
            $this->assertStringContainsString('409', $status2);
            $this->assertStringNotContainsString('?t=', $headers2 . $body2);
            $this->assertStringContainsString('već započeta', $body2);
            $this->assertSame($token, file_get_contents($dir . '/storage/tmp/install-token'), 'ključ se ne menja');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testSmeseSamoPostojecaVerzija(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Package::version(dirname(__DIR__, 2)));
    }
}
