<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Services\Backup;

final class BackupTest extends InstallTestCase
{
    public function testKopijaIPovratakSqliteBaze(): void
    {
        $i = $this->installation('1.0.0');
        $pdo = $i['pdo'];
        $pdo->exec("INSERT INTO clients (name, slug, api_key_prefix, api_key_hash, active, created_at) VALUES ('Pera DOO', '"
            . str_repeat('a', 32) . "', 'abcdefgh', '" . str_repeat('b', 64) . "', 1, '2026-10-29 10:00:00')");
        $b = new Backup($i['root'], $pdo);
        $meta = $b->create('1.0.0', 'test');
        $this->assertTrue(Backup::validName($meta['name']));
        $this->assertSame('sqlite', $meta['driver']);
        $this->assertContains('clients', $meta['tables']);
        $this->assertGreaterThan(0, $meta['size']);

        // Posle kopije: nov red, nova tabela, izmena podešavanja.
        $pdo->exec("INSERT INTO clients (name, slug, api_key_prefix, api_key_hash, active, created_at) VALUES ('Novi', '"
            . str_repeat('c', 32) . "', 'abcdefgi', '" . str_repeat('d', 64) . "', 1, '2026-10-29 11:00:00')");
        $pdo->exec('CREATE TABLE posle (id INTEGER)');
        $i['settings']->set('version', '1.1.0');

        $b->restoreDatabase($meta['name']);
        $this->assertSame(['Pera DOO'], array_column($pdo->query('SELECT name FROM clients')->fetchAll(), 'name'));
        $this->assertNotContains('posle', Backup::listTables($pdo));
        $this->assertSame('1.0.0', $i['settings']->get('version'));
        // AUTOINCREMENT brojač je vraćen: sledeći id je 2, ne 3.
        $pdo->exec("INSERT INTO clients (name, slug, api_key_prefix, api_key_hash, active, created_at) VALUES ('Treći', '"
            . str_repeat('e', 32) . "', 'abcdefgj', '" . str_repeat('f', 64) . "', 1, '2026-10-29 12:00:00')");
        $this->assertSame(2, (int) $pdo->query("SELECT id FROM clients WHERE name = 'Treći'")->fetchColumn());
        // Indeksi su vraćeni.
        $idx = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'receipts_client_sdc_idx'")->fetchColumn();
        $this->assertSame('receipts_client_sdc_idx', $idx);
    }

    public function testFajloviKopijeIPovratakFajlova(): void
    {
        $i = $this->installation('1.0.0');
        mkdir($i['root'] . '/releases/x', 0755, true);
        file_put_contents($i['root'] . '/racunator-panel-install.php', 'stub');
        $b = new Backup($i['root'], $i['pdo']);
        $meta = $b->create('1.0.0');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open((string) $b->filePath($meta['name'], Backup::FILES_ZIP)));
        $names = [];
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $names[] = $zip->getNameIndex($k);
        }
        $zip->close();
        $this->assertContains('src/version.php', $names);
        $this->assertContains('config.php', $names);
        foreach ($names as $n) {
            $this->assertDoesNotMatchRegularExpression('#^(storage|releases|_old)/|^racunator-panel-install#', $n);
        }

        file_put_contents($i['root'] . '/src/marker.txt', 'pokvareno');
        file_put_contents($i['root'] . '/config.php', 'izmenjen config');
        $b->restoreFiles($meta['name']);
        $this->assertStringContainsString('1.0.0 staro', (string) file_get_contents($i['root'] . '/src/marker.txt'));
        $this->assertSame('pokvareno', file_get_contents($i['root'] . '/_old/src/marker.txt'));
        $this->assertSame('izmenjen config', file_get_contents($i['root'] . '/config.php'), 'config.php se pri povratku ne dira');
        $this->assertDirectoryDoesNotExist($i['root'] . '/releases/vracanje-' . $meta['name']);
    }

    public function testCuvajuSeTriNajnovijeIImenaSeProveravaju(): void
    {
        $i = $this->installation('1.0.0');
        $b = new Backup($i['root'], $i['pdo']);
        $names = [];
        for ($k = 0; $k < 5; $k++) {
            $names[] = $b->create('1.0.' . $k)['name'];
        }
        $list = $b->list();
        $this->assertCount(3, $list);
        $this->assertSame(array_reverse(array_slice($names, 2)), array_column($list, 'name'));
        $this->assertCount(5, array_unique($names));
        $this->assertSame('1.0.4', $list[0]['version']);

        $this->assertNull($b->find('../../etc'));
        $this->assertNull($b->filePath($names[4], '../config.php'));
        $this->assertNull($b->filePath('20261029-999999', Backup::FILES_ZIP));
        $this->assertNotNull($b->filePath($names[4], Backup::DB_SQLITE));
        $this->assertFalse(Backup::validName('2026-10-29'));
    }
}
