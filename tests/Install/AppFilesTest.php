<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Services\AppFiles;

final class AppFilesTest extends InstallTestCase
{
    public function testBezbednaImena(): void
    {
        foreach (['src/App.php', 'public/.htaccess', 'docs/'] as $ok) {
            $this->assertTrue(AppFiles::safeEntryName($ok), $ok);
        }
        foreach (['../x', '/etc/passwd', 'a/../../b', 'a\\b', 'C:/x', "a\0b", '', './a', 'a//b'] as $bad) {
            $this->assertFalse(AppFiles::safeEntryName($bad), $bad);
        }
    }

    public function testZipSlipSeOdbija(): void
    {
        $zipFile = $this->tmp . '/zlo.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('../izvan.txt', 'x');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        AppFiles::extractZip($zipFile, $this->tmp . '/cilj');
    }

    public function testZamenaIPovratak(): void
    {
        $root = $this->tmp . '/root';
        $this->makeApp($root, '1.0.0', 'staro');
        $src = $root . '/releases/2.0.0';
        $this->makeApp($src, '2.0.0', 'novo');
        $journal = AppFiles::swapIn($root, $src);
        $this->assertSame('2.0.0', AppFiles::versionOf($root));
        $this->assertSame('1.0.0', AppFiles::versionOf($root . '/_old'));
        AppFiles::undoSwap($root, $src, $journal);
        $this->assertSame('1.0.0', AppFiles::versionOf($root));
        $this->assertSame('2.0.0', AppFiles::versionOf($src));
        $this->assertStringContainsString('1.0.0', (string) file_get_contents($root . '/README.md'));
    }

    public function testDelimicnaZamenaSeSamaVraca(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root zaobilazi prava pristupa');
        }
        $root = $this->tmp . '/root';
        $this->makeApp($root, '1.0.0', 'staro');
        $src = $root . '/releases/2.0.0';
        $this->makeApp($src, '2.0.0', 'novo');
        // Direktorijum bez prava pisanja ne može da se premesti u drugi roditeljski direktorijum (Linux) → pad na docs/.
        chmod($src . '/docs', 0555);
        try {
            AppFiles::swapIn($root, $src);
            $this->fail('Zamena je morala da padne.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('docs', $e->getMessage());
        } finally {
            chmod($src . '/docs', 0755);
        }
        // Sve što je već bilo zamenjeno je vraćeno.
        $this->assertSame('1.0.0', AppFiles::versionOf($root));
        foreach (['public', 'src', 'install', 'docs'] as $d) {
            $this->assertStringContainsString($d . ' 1.0.0 staro', (string) file_get_contents($root . '/' . $d . '/marker.txt'));
            $this->assertStringContainsString($d . ' 2.0.0 novo', (string) file_get_contents($src . '/' . $d . '/marker.txt'));
        }
    }

    public function testManifest(): void
    {
        $dir = $this->tmp . '/pkg';
        $this->makeApp($dir, '1.0.0');
        $this->writeManifest($dir);
        $this->assertSame([], AppFiles::verifyManifest($dir));
        unlink($dir . '/docs/marker.txt');
        file_put_contents($dir . '/src/marker.txt', 'izmena');
        $errors = AppFiles::verifyManifest($dir);
        $this->assertCount(2, $errors);
        unlink($dir . '/MANIFEST.sha256');
        $this->assertNotSame([], AppFiles::verifyManifest($dir));
    }

    public function testManifestOdbijaFajloveKojihNemaUManifestu(): void
    {
        $dir = $this->tmp . '/pkg';
        $this->makeApp($dir, '1.0.0');
        $this->writeManifest($dir);
        file_put_contents($dir . '/public/visak.php', '<?php');
        mkdir($dir . '/src/novi');
        file_put_contents($dir . '/src/novi/.htaccess', 'Require all granted');
        symlink('/etc/passwd', $dir . '/docs/link');
        $errors = AppFiles::verifyManifest($dir);
        $this->assertContains('Fajl nije u manifestu: public/visak.php', $errors);
        $this->assertContains('Fajl nije u manifestu: src/novi/.htaccess', $errors);
        $this->assertContains('Fajl nije u manifestu: docs/link', $errors);
        $this->assertCount(3, $errors);
    }

    public function testReleasesIOldSuZabranjeniPrekoVeba(): void
    {
        $root = $this->tmp . '/root';
        mkdir($root);
        $dir = AppFiles::stagingDir($root, '2.0.0');
        $this->assertSame($root . '/releases/2.0.0', $dir);
        $ht = (string) file_get_contents($root . '/releases/.htaccess');
        $this->assertStringContainsString('Require all denied', $ht);
        $this->assertStringContainsString('<If "true">', $ht, 'zabrana važi i kad poddirektorijum ima „Require all granted"');
        $this->assertStringNotContainsString('RewriteEngine', $ht);
        mkdir($dir);
        file_put_contents($dir . '/.htaccess', "RewriteEngine On\n");
        file_put_contents($dir . '/config.php', '<?php return [];');
        AppFiles::neutralizeStaging($dir);
        $this->assertFileDoesNotExist($dir . '/.htaccess');
        $this->assertFileExists($dir . '/htaccess.dist');
        $this->assertFileDoesNotExist($dir . '/config.php');
        AppFiles::removeTree($dir);
        AppFiles::removeEmptyStaging($root);
        $this->assertDirectoryDoesNotExist($root . '/releases');
        foreach (['', '.', '..', 'a/b'] as $bad) {
            try {
                AppFiles::stagingDir($root, $bad);
                $this->fail('Prihvaćeno ime ' . $bad);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAtomicanUpisSaPravima0600(): void
    {
        $old = umask(0022);
        try {
            AppFiles::writeAtomic($this->tmp . '/tajna.php', '<?php return [];', 0600);
            $fp = AppFiles::openPrivate($this->tmp . '/kopija.sql');
            fclose($fp);
        } finally {
            umask($old);
        }
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->tmp . '/tajna.php')), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->tmp . '/kopija.sql')), -4));
        $this->assertSame(0022, umask(), 'umask se vraća');
        $this->assertSame([], glob($this->tmp . '/tajna.php.tmp-*') ?: []);
    }
}
