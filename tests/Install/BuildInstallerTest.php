<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Services\AppFiles;
use RacunatorPanel\Tools\Package;

/** Build stvarnog repoa: ZIP + samoraspakujući install fajl, raspakivanje u privremeni folder (sa i bez ext-zip). */
final class BuildInstallerTest extends InstallTestCase
{
    private static ?string $buildDir = null;

    private function build(): string
    {
        if (self::$buildDir === null || !is_file(self::$buildDir . '/' . Package::INSTALLER_NAME)) {
            self::$buildDir = sys_get_temp_dir() . '/rp-build-' . bin2hex(random_bytes(4));
            $root = dirname(__DIR__, 2);
            $zip = Package::buildZip($root, self::$buildDir . '/racunator-panel-' . Package::version($root) . '.zip');
            Package::buildInstaller($root, $zip['file'], self::$buildDir . '/' . Package::INSTALLER_NAME);
        }
        return self::$buildDir;
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
        foreach (['MANIFEST.sha256', 'src/version.php', 'public/index.php', 'install/index.php', 'install/Updater.php', '.htaccess',
            'storage/.htaccess', 'LICENSE', 'README.md', 'CHANGELOG.md', 'src/Services/ReleaseChecker.php'] as $f) {
            $this->assertNotFalse($zip->locateName($f), $f);
        }
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $name = (string) $zip->getNameIndex($k);
            $this->assertDoesNotMatchRegularExpression('#^(tests|tools|vendor|plans|build|storage/(?!\.htaccess$))|\.DS_Store$|^config\.php$#', $name);
        }
        $zip->close();
        $head = (string) file_get_contents($build . '/' . Package::INSTALLER_NAME, false, null, 0, 4096);
        $this->assertStringContainsString('RACUNATOR_PANEL_INSTALLER', $head);
        $this->assertStringNotContainsString('@@VERSION@@', $head);
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
        $this->assertSame([], AppFiles::verifyManifest($dir));
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
        [$rc, $out] = $this->runStub($dir);
        $this->assertSame(0, $rc, $out);
        $v = Package::version(dirname(__DIR__, 2));
        $this->assertSame([], AppFiles::verifyManifest($dir . '/releases/' . $v));
        $this->assertFileDoesNotExist($dir . '/src', 'postojeća instalacija se ne prepisuje');
        $this->assertFileDoesNotExist($dir . '/' . Package::INSTALLER_NAME, 'install fajl briše sebe');
        $pending = json_decode((string) file_get_contents($dir . '/storage/tmp/manual-update.json'), true);
        $this->assertSame($v, $pending['version']);
    }

    public function testSmeseSamoPostojecaVerzija(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Package::version(dirname(__DIR__, 2)));
    }
}
