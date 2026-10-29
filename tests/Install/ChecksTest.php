<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Install\Checks;
use RacunatorPanel\Install\SystemProbe;


final class ChecksTest extends InstallTestCase
{
    /** @return array<string,array{key:string,label:string,status:string,message:string,hint:string}> */
    private function check(FakeProbe $p, bool $https = true): array
    {
        $out = [];
        foreach ((new Checks($this->tmp, 'https://primer.rs/panel/install', $https, $p))->run() as $r) {
            $out[$r['key']] = $r;
        }
        return $out;
    }

    public function testSveUReduKadHostingIspunjavaUslove(): void
    {
        $r = $this->check(new FakeProbe());
        $this->assertSame(Checks::OK, Checks::overall(array_values($r)));
        foreach ($r as $item) {
            $this->assertSame(Checks::OK, $item['status'], $item['key']);
            $this->assertNotSame('', $item['message']);
        }
    }

    public function testStarPhpJeCrven(): void
    {
        $p = new FakeProbe();
        $p->php = '7.4.33';
        $r = $this->check($p);
        $this->assertSame(Checks::FAIL, $r['php']['status']);
        $this->assertStringContainsString('8.1', $r['php']['message']);
        $this->assertNotSame('', $r['php']['hint']);
        $this->assertSame(Checks::FAIL, Checks::overall(array_values($r)));
    }

    public function testBezPdoDrajveraJeCrvenAJedanDrajverJeDovoljan(): void
    {
        $p = new FakeProbe();
        $p->ext['pdo_mysql'] = false;
        $this->assertSame(Checks::OK, $this->check($p)['pdo']['status']);
        $p->ext['pdo_sqlite'] = false;
        $this->assertSame(Checks::FAIL, $this->check($p)['pdo']['status']);
    }

    public function testZipSodiumIHttpSuUpozorenja(): void
    {
        $p = new FakeProbe();
        $p->ext['zip'] = false;
        $p->ext['sodium'] = false;
        $p->ext['curl'] = false;
        $p->iniValues['allow_url_fopen'] = '0';
        $r = $this->check($p);
        $this->assertSame(Checks::WARN, $r['ext_zip']['status']);
        $this->assertSame(Checks::WARN, $r['ext_sodium']['status']);
        $this->assertStringContainsString('isključeno', $r['ext_sodium']['message'], 'bez sodium-a nema mrežnog ažuriranja');
        $this->assertSame(Checks::WARN, $r['http']['status']);
        $this->assertSame(Checks::WARN, Checks::overall(array_values($r)));
    }

    public function testObavezneEkstenzijeSuCrvene(): void
    {
        foreach (['openssl', 'mbstring', 'json', 'session'] as $ext) {
            $p = new FakeProbe();
            $p->ext[$ext] = false;
            $this->assertSame(Checks::FAIL, $this->check($p)['ext_' . $ext]['status'], $ext);
        }
    }

    public function testMemorijaProstorPisanje(): void
    {
        $p = new FakeProbe();
        $p->iniValues['memory_limit'] = '32M';
        $p->free = 10 * 1024 * 1024;
        $p->writable = false;
        $r = $this->check($p);
        $this->assertSame(Checks::FAIL, $r['memory']['status']);
        $this->assertSame(Checks::FAIL, $r['disk']['status']);
        $this->assertSame(Checks::FAIL, $r['write_root']['status']);
        $p = new FakeProbe();
        $p->iniValues['memory_limit'] = '-1';
        $p->free = null;
        $r = $this->check($p);
        $this->assertSame(Checks::OK, $r['memory']['status']);
        $this->assertSame(Checks::WARN, $r['disk']['status']);
    }

    public function testHttpsIModRewrite(): void
    {
        $p = new FakeProbe();
        $this->assertSame(Checks::WARN, $this->check($p, false)['https']['status']);
        $p->rewrite = false;
        $this->assertSame(Checks::FAIL, $this->check($p)['rewrite']['status']);
        $p->rewrite = null;
        $r = $this->check($p);
        $this->assertSame(Checks::WARN, $r['rewrite']['status']);
        $this->assertStringContainsString('/health', $r['rewrite']['message']);
    }

    public function testOpenBasedirSeSamoNavodi(): void
    {
        $p = new FakeProbe();
        $p->iniValues['open_basedir'] = '/home/nalog:/tmp';
        $r = $this->check($p);
        $this->assertSame(Checks::OK, $r['open_basedir']['status']);
    }

    public function testBytes(): void
    {
        $this->assertSame(128 * 1024 * 1024, Checks::bytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, Checks::bytes('1G'));
        $this->assertSame(512 * 1024, Checks::bytes('512k'));
        $this->assertSame(-1, Checks::bytes('-1'));
        $this->assertSame(1000, Checks::bytes('1000'));
    }

    public function testStorageDostupanPrekoVebaJeCrven(): void
    {
        mkdir($this->tmp . '/storage/backups', 0755, true);
        $p = new FakeProbe();
        $r = $this->check($p);
        $this->assertSame(Checks::OK, $r['storage_web']['status']);
        $call = $p->exposedCalls[0];
        $this->assertSame('https://primer.rs/panel/storage', $call['url']);
        $this->assertSame($this->tmp . '/storage', $call['dir']);
        $this->assertMatchesRegularExpression('/\.txt$/', $call['names'][0]);
        $this->assertMatchesRegularExpression('#^backups/.*\.zip$#', $call['names'][1]);
        $this->assertMatchesRegularExpression('/\.sql$/', $call['names'][2]);

        $p->exposed = ['proba.txt', 'proba.zip'];
        $r = $this->check($p);
        $this->assertSame(Checks::FAIL, $r['storage_web']['status']);
        $this->assertStringContainsString('van web root-a', $r['storage_web']['hint']);
        $this->assertSame(Checks::FAIL, Checks::overall(array_values($r)));

        $p->exposed = null;
        $this->assertSame(Checks::WARN, $this->check($p)['storage_web']['status']);
    }

    public function testStorageVanWebRootaSeNeProbaPrekoVeba(): void
    {
        $p = new FakeProbe();
        $p->exposed = ['proba.txt'];
        $out = [];
        foreach ((new Checks($this->tmp . '/panel', 'https://primer.rs/panel/install', true, $p, $this->tmp . '/podaci'))->run() as $item) {
            $out[$item['key']] = $item;
        }
        $this->assertSame(Checks::OK, $out['storage_web']['status']);
        $this->assertSame([], $p->exposedCalls);
    }

    public function testPraviProbniFajloviSeBrisu(): void
    {
        mkdir($this->tmp . '/s/backups', 0755, true);
        // Adresa na koju niko ne odgovara: poziv nije moguć (null), a probni fajlovi ne ostaju.
        $res = (new SystemProbe())->webExposed($this->tmp . '/s', 'http://127.0.0.1:9/storage', ['a.txt', 'backups/b.zip']);
        $this->assertNull($res);
        $this->assertFileDoesNotExist($this->tmp . '/s/a.txt');
        $this->assertFileDoesNotExist($this->tmp . '/s/backups/b.zip');
    }

    public function testPraviModRewriteTestBezAdreseNijeMoguc(): void
    {
        $this->assertNull((new SystemProbe())->modRewriteWorks($this->tmp, null));
    }
}
